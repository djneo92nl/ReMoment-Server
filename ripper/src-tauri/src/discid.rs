//! Reads the table of contents of the disc in the drive and turns it into a MusicBrainz disc ID.
//! macOS reads it from `drutil toc`; other platforms come with the ripper (cyanrip) later.

use base64::{engine::general_purpose::STANDARD, Engine};
use serde::Serialize;
use sha1::{Digest, Sha1};

/// What the drive says about the disc. Offsets and the lead-out are absolute frames (75 per second, the
/// two-second lead-in included), which is what a disc ID is made of.
#[derive(Serialize, Debug, PartialEq)]
pub struct Toc {
    pub first: u32,
    pub last: u32,
    pub leadout: u32,
    /// Start frame of track `first + i`.
    pub offsets: Vec<u32>,
}

#[derive(Serialize, Debug)]
pub struct TrackSpan {
    pub number: u32,
    /// Where the track starts on the disc (sector 0 is the start of track 1) and how many sectors it has.
    pub first_sector: u32,
    pub sectors: u32,
}

#[derive(Serialize, Debug)]
pub struct Disc {
    pub disc_id: String,
    pub track_count: u32,
    /// Length of each track in seconds, from the table of contents.
    pub seconds: Vec<u32>,
    pub tracks: Vec<TrackSpan>,
    /// The drive's device, for the ripper (empty when unknown).
    pub device: String,
}

/// "05:06.32" → frames
fn msf(text: &str) -> Option<u32> {
    let (minutes, rest) = text.split_once(':')?;
    let (seconds, frames) = rest.split_once('.')?;
    Some((minutes.trim().parse::<u32>().ok()? * 60 + seconds.parse::<u32>().ok()?) * 75 + frames.parse::<u32>().ok()?)
}

fn after_colon<'a>(line: &'a str, label: &str) -> Option<&'a str> {
    line.trim_start().strip_prefix(label).map(|rest| rest.trim_start_matches(':').trim())
}

/// The output of `drutil toc`.
pub fn parse_drutil(text: &str) -> Result<Toc, String> {
    let (mut first, mut last, mut leadout) = (None, None, None);
    let mut tracks: Vec<(u32, u32)> = vec![];

    for line in text.lines() {
        if let Some(value) = after_colon(line, "First track") {
            first = value.parse::<u32>().ok();
        } else if let Some(value) = after_colon(line, "Last track") {
            last = value.parse::<u32>().ok();
        } else if let Some(value) = after_colon(line, "Lead-out") {
            leadout = value.split_whitespace().next().and_then(msf);
        } else if line.trim_start().starts_with("Session") && line.contains("Track") {
            let (head, tail) = line.split_once(':').ok_or("Unreadable track line.")?;
            let number = head.rsplit("Track").next().and_then(|n| n.trim().parse::<u32>().ok()).ok_or("Unreadable track number.")?;
            let start = tail.split_whitespace().next().and_then(msf).ok_or("Unreadable track start.")?;
            if !tail.contains("audio") {
                return Err("This disc has data tracks; only plain audio CDs are supported for now.".into());
            }
            tracks.push((number, start));
        }
    }

    let (first, last, leadout) = (first.ok_or("No first track in the table of contents.")?, last.ok_or("No last track.")?, leadout.ok_or("No lead-out.")?);
    tracks.sort();
    let expected: Vec<u32> = (first..=last).collect();
    if tracks.iter().map(|t| t.0).collect::<Vec<_>>() != expected {
        return Err("The table of contents is incomplete.".into());
    }
    let offsets: Vec<u32> = tracks.iter().map(|t| t.1).collect();
    if offsets.windows(2).any(|w| w[0] >= w[1]) || offsets.last().is_some_and(|&o| o >= leadout) {
        return Err("The table of contents is not in order.".into());
    }
    Ok(Toc { first, last, leadout, offsets })
}

impl Toc {
    /// libdiscid's algorithm: SHA-1 over first and last track, the lead-out and 99 track offsets (0 for the
    /// tracks the disc lacks), as upper-case hex; base64 with `.`, `_` and `-` for `+`, `/` and `=`.
    pub fn disc_id(&self) -> String {
        let mut text = format!("{:02X}{:02X}{:08X}", self.first, self.last, self.leadout);
        for track in 1..=99u32 {
            let offset = if (self.first..=self.last).contains(&track) { self.offsets[(track - self.first) as usize] } else { 0 };
            text.push_str(&format!("{offset:08X}"));
        }
        let digest = Sha1::digest(text.as_bytes());
        STANDARD.encode(digest).replace('+', ".").replace('/', "_").replace('=', "-")
    }

    pub fn seconds(&self) -> Vec<u32> {
        let ends = self.offsets.iter().skip(1).copied().chain([self.leadout]);
        self.offsets.iter().zip(ends).map(|(start, end)| (end - start) / 75).collect()
    }

    pub fn disc(&self, device: String) -> Disc {
        let ends = self.offsets.iter().skip(1).copied().chain([self.leadout]);
        let tracks = self
            .offsets
            .iter()
            .zip(ends)
            .enumerate()
            .map(|(i, (start, end))| TrackSpan { number: self.first + i as u32, first_sector: start - 150, sectors: end - start })
            .collect();
        Disc { disc_id: self.disc_id(), track_count: self.offsets.len() as u32, seconds: self.seconds(), tracks, device }
    }
}

/// Reads the disc in the drive.
#[cfg(target_os = "macos")]
pub fn read() -> Result<Disc, String> {
    let output = std::process::Command::new("drutil").arg("toc").output().map_err(|e| format!("Can't run drutil: {e}"))?;
    let text = String::from_utf8_lossy(&output.stdout);
    if !output.status.success() || !text.contains("First track") {
        return Err("No audio disc found in the drive.".into());
    }
    let device = device();
    if device.is_empty() {
        return Err("The drive stopped responding. Check its connection and try again.".into());
    }
    Ok(parse_drutil(&text)?.disc(device))
}

/// The raw device of the drive with a disc: `drutil status` names `/dev/disk18`, the ripper wants `/dev/rdisk18`.
#[cfg(target_os = "macos")]
fn device() -> String {
    let Ok(output) = std::process::Command::new("drutil").arg("status").output() else { return String::new() };
    String::from_utf8_lossy(&output.stdout)
        .split_whitespace()
        .find_map(|word| word.strip_prefix("/dev/disk"))
        .map(|n| format!("/dev/rdisk{n}"))
        .unwrap_or_default()
}

#[cfg(not(target_os = "macos"))]
pub fn read() -> Result<Disc, String> {
    Err("Reading the disc is only built for macOS so far.".into())
}

#[cfg(test)]
mod tests {
    use super::*;

    const TOC: &str = include_str!("../fixtures/drutil-toc.txt");

    #[test]
    fn a_drutil_toc_is_read() {
        let toc = parse_drutil(TOC).unwrap();

        assert_eq!((toc.first, toc.last, toc.leadout), (1, 14, 355_500));
        assert_eq!(toc.offsets.len(), 14);
        assert_eq!(toc.offsets[0], 150); // 00:02.00
        assert_eq!(toc.offsets[1], (5 * 60 + 6) * 75 + 32); // 05:06.32
    }

    #[test]
    fn the_disc_id_has_the_musicbrainz_shape() {
        let id = parse_drutil(TOC).unwrap().disc_id();

        assert_eq!(id.len(), 28);
        assert!(crate::musicbrainz::valid_disc_id(&id));
        let disc = parse_drutil(TOC).unwrap().disc("/dev/rdisk18".into());
        assert_eq!(disc.seconds.len(), 14);
        // what cd-paranoia -Q said about this disc: track 1 begins at 0 with 22832 sectors, track 2 at 22832 with 24615
        assert_eq!((disc.tracks[0].first_sector, disc.tracks[0].sectors), (0, 22832));
        assert_eq!((disc.tracks[1].first_sector, disc.tracks[1].sectors), (22832, 24615));
        assert_eq!((disc.tracks[13].first_sector, disc.tracks[13].sectors), (311182, 44168));
    }

    #[test]
    fn data_tracks_and_gaps_are_refused() {
        assert!(parse_drutil(&TOC.replace("Track  3:      10:34.47  2ch audio", "Track  3:      10:34.47  data")).is_err());
        assert!(parse_drutil(&TOC.replace("Last track:                14", "Last track:                15")).is_err());
    }

    /// With a disc in the drive: `cargo test live_disc -- --ignored --nocapture`
    #[tokio::test]
    #[ignore]
    async fn live_disc_is_looked_up_on_musicbrainz() {
        let disc = read().expect("a disc in the drive");
        println!("disc id {} ({} tracks, {:?} s)", disc.disc_id, disc.track_count, disc.seconds);

        let releases = crate::musicbrainz::lookup(&disc.disc_id).await.unwrap();
        for r in &releases {
            println!("{} - {} ({:?}, {:?}) {} tracks", r.artist, r.title, r.date, r.country, r.tracks.len());
        }
        assert!(!releases.is_empty(), "MusicBrainz does not know this disc id");
        assert!(releases.iter().all(|r| r.tracks.len() as u32 == disc.track_count));
    }
}
