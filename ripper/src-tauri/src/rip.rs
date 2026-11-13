//! Rips one track of the disc to FLAC. This is the platform-specific part: on macOS `cd-paranoia`
//! (Homebrew `libcdio-paranoia`) reads the track with error detection and ffmpeg encodes it. Another
//! platform brings its own `rip_track`; everything around it (tags, upload, review) is shared.

use serde::Serialize;
use std::path::{Path, PathBuf};
use tauri::ipc::Channel;

#[derive(Serialize, Clone)]
#[serde(tag = "event", rename_all = "camelCase")]
pub enum RipEvent {
    Progress { percent: u32 },
    Encoding,
}

#[derive(Serialize)]
pub struct Ripped {
    pub path: String,
    /// Lines in the ripper's log that mention skips, scratches, retries or drift: 0 for a clean read.
    pub problems: u32,
}

/// Where ripped files wait until they are uploaded.
pub fn work_dir() -> PathBuf {
    std::env::temp_dir().join("remoment-ripper")
}

/// A file may only be discarded when it is one of ours.
pub fn discard(path: &Path) -> Result<(), String> {
    if !path.starts_with(work_dir()) || path.components().any(|c| c.as_os_str() == "..") {
        return Err("Not a file of this app.".into());
    }
    std::fs::remove_file(path).map_err(|e| e.to_string())
}

/// `/dev/rdisk18` or `/dev/disk18`: nothing else is handed to the ripper as a device.
pub fn valid_device(device: &str) -> bool {
    device
        .strip_prefix("/dev/rdisk")
        .or_else(|| device.strip_prefix("/dev/disk"))
        .is_some_and(|n| !n.is_empty() && n.chars().all(|c| c.is_ascii_digit()))
}

/// The sector number in cd-paranoia's progress line: `(== PROGRESS == [   | 022831 00 ] == :^D * ==)`.
pub fn last_sector(progress: &str) -> Option<u32> {
    let after = &progress[progress.rfind('|')? + 1..];
    after.split_whitespace().next()?.parse().ok()
}

pub fn percent(sector: u32, first_sector: u32, sectors: u32) -> u32 {
    if sectors == 0 {
        return 0;
    }
    ((u64::from(sector.saturating_sub(first_sector)) * 100) / u64::from(sectors)).min(100) as u32
}

fn problems(log: &str) -> u32 {
    log.lines()
        .filter(|l| !l.contains("PROGRESS"))
        .filter(|l| ["skip", "scratch", "retry", "drift"].iter().any(|w| l.to_lowercase().contains(w)))
        .count() as u32
}

#[cfg(target_os = "macos")]
pub fn rip_track(
    device: &str,
    track: u32,
    first_sector: u32,
    sectors: u32,
    on_event: &Channel<RipEvent>,
) -> Result<Ripped, String> {
    use std::io::Read;
    use std::process::{Command, Stdio};

    if !valid_device(device) {
        return Err("That is not a disc drive.".into());
    }
    let dir = work_dir();
    std::fs::create_dir_all(&dir).map_err(|e| e.to_string())?;
    let (wav, flac, log) = (dir.join(format!("{track:02}.wav")), dir.join(format!("{track:02}.flac")), dir.join(format!("{track:02}.log")));

    let mut child = Command::new("cd-paranoia")
        .args(["-d", device, "-w", "-l"])
        .arg(&log)
        .arg(track.to_string())
        .arg(&wav)
        .stdout(Stdio::null())
        .stderr(Stdio::piped())
        .spawn()
        .map_err(|e| format!("Can't run cd-paranoia (brew install libcdio-paranoia): {e}"))?;

    let mut stderr = child.stderr.take().ok_or("No output from cd-paranoia.")?;
    let (mut chunk, mut recent, mut shown) = ([0u8; 512], Vec::<u8>::new(), u32::MAX);
    loop {
        let n = stderr.read(&mut chunk).map_err(|e| e.to_string())?;
        if n == 0 {
            break;
        }
        recent.extend_from_slice(&chunk[..n]);
        if recent.len() > 2048 {
            recent.drain(..recent.len() - 1024);
        }
        if let Some(sector) = last_sector(&String::from_utf8_lossy(&recent)) {
            let now = percent(sector, first_sector, sectors);
            if now != shown {
                shown = now;
                let _ = on_event.send(RipEvent::Progress { percent: now });
            }
        }
    }
    let status = child.wait().map_err(|e| e.to_string())?;
    if !status.success() {
        let _ = std::fs::remove_file(&wav);
        return Err(format!("cd-paranoia stopped ({status}). Is the drive still connected? Reading track {track} can be retried."));
    }

    let _ = on_event.send(RipEvent::Encoding);
    let encoded = Command::new("ffmpeg")
        .args(["-loglevel", "error", "-y", "-i"])
        .arg(&wav)
        .args(["-c:a", "flac", "-compression_level", "8"])
        .arg(&flac)
        .output()
        .map_err(|e| format!("Can't run ffmpeg: {e}"))?;
    let _ = std::fs::remove_file(&wav);
    if !encoded.status.success() {
        return Err(format!("ffmpeg could not encode track {track}: {}", String::from_utf8_lossy(&encoded.stderr).trim()));
    }

    let problems = problems(&std::fs::read_to_string(&log).unwrap_or_default());
    Ok(Ripped { path: flac.to_string_lossy().into_owned(), problems })
}

#[cfg(not(target_os = "macos"))]
pub fn rip_track(_: &str, _: u32, _: u32, _: u32, _: &Channel<RipEvent>) -> Result<Ripped, String> {
    Err("Ripping is only built for macOS so far.".into())
}

#[cfg(target_os = "macos")]
pub fn eject() -> Result<(), String> {
    let out = std::process::Command::new("drutil").arg("eject").output().map_err(|e| e.to_string())?;
    if out.status.success() { Ok(()) } else { Err("The disc could not be ejected.".into()) }
}

#[cfg(not(target_os = "macos"))]
pub fn eject() -> Result<(), String> {
    Err("Ejecting is only built for macOS so far.".into())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_sector_comes_out_of_the_progress_line() {
        assert_eq!(last_sector(" (== PROGRESS == [                              | 022831 00 ] == :^D * ==)"), Some(22831));
        assert_eq!(last_sector("(== PROGRESS == [>                             | 000123 00 ] == :-) . ==)"), Some(123));
        assert_eq!(last_sector("nothing here"), None);
    }

    #[test]
    fn progress_is_relative_to_the_track() {
        assert_eq!(percent(22832 + 12308, 22832, 24615), 50);
        assert_eq!(percent(10, 22832, 24615), 0); // before the track starts
        assert_eq!(percent(99999, 0, 100), 100);
        assert_eq!(percent(5, 0, 0), 0);
    }

    #[test]
    fn only_drive_devices_are_accepted() {
        assert!(valid_device("/dev/rdisk18"));
        assert!(valid_device("/dev/disk4"));
        assert!(!valid_device("/dev/rdisk"));
        assert!(!valid_device("/dev/rdisk1; rm -rf /"));
        assert!(!valid_device("/etc/passwd"));
    }

    #[test]
    fn a_clean_log_has_no_problems() {
        assert_eq!(problems("cd-paranoia -d x\n (== PROGRESS == [ | 022831 00 ] == :^D * ==)\n"), 0);
        assert_eq!(problems("a\n##: -2 [verify_skip_matching] @ 100\n[scratch detected]\n"), 2);
    }

    #[test]
    fn only_our_own_files_can_be_discarded() {
        assert!(discard(Path::new("/etc/hosts")).is_err());
        assert!(discard(&work_dir().join("../../etc/hosts")).is_err());
    }

    /// With a disc in the drive: `cargo test live_rip -- --ignored --nocapture` (rips track 10, about 4 minutes)
    #[cfg(target_os = "macos")]
    #[test]
    #[ignore]
    fn live_rip_of_one_track() {
        use std::sync::{Arc, Mutex};

        let disc = crate::discid::read().expect("a disc in the drive");
        assert!(valid_device(&disc.device), "device {:?}", disc.device);
        let span = &disc.tracks[9];
        let events = Arc::new(Mutex::new(Vec::<String>::new()));
        let seen = events.clone();
        let channel = Channel::<RipEvent>::new(move |body| {
            seen.lock().unwrap().push(format!("{body:?}"));
            Ok(())
        });

        let started = std::time::Instant::now();
        let ripped = rip_track(&disc.device, span.number, span.first_sector, span.sectors, &channel).unwrap();
        println!("ripped in {:?}: {} (problems: {})", started.elapsed(), ripped.path, ripped.problems);

        let events = events.lock().unwrap();
        println!("{} events, first {:?}, last {:?}", events.len(), events.first(), events.last());
        assert!(events.len() > 5, "progress was reported");
        assert!(events.iter().any(|e| e.contains("100")), "reached 100%");
        assert!(std::fs::metadata(&ripped.path).unwrap().len() > 1_000_000);
        assert!(!work_dir().join("10.wav").exists(), "the wav is removed");
        assert_eq!(ripped.problems, 0);
    }
}
