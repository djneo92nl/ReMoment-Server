mod library_check;
mod musicbrainz;
mod tagging;
mod server;

use server::{Session, Uploaded};
use std::path::Path;

/// The server the stored token belongs to, or `None` when signed out (or the token no longer works).
#[tauri::command]
async fn session_status() -> Result<Option<String>, String> {
    let Some(session) = server::load() else {
        return Ok(None);
    };
    if server::check(&session).await? {
        Ok(Some(session.url))
    } else {
        server::clear();
        Ok(None)
    }
}

#[tauri::command]
async fn connect(url: String, password: String) -> Result<String, String> {
    let session = server::login(&url, &password).await?;
    server::save(&session)?;
    Ok(session.url)
}

#[tauri::command]
async fn sign_out() -> Result<(), String> {
    if let Some(session) = server::load() {
        // Best effort: the local token goes either way.
        let _ = server::revoke(&session).await;
    }
    server::clear();
    Ok(())
}

#[tauri::command]
async fn upload_file(path: String) -> Result<Uploaded, String> {
    let session: Session = server::load().ok_or("Not connected to a server.")?;
    server::upload(&session, Path::new(&path)).await
}

/// The releases MusicBrainz lists for a disc ID (empty when the disc is unknown there).
#[tauri::command]
async fn lookup_disc(disc_id: String) -> Result<Vec<musicbrainz::Release>, String> {
    musicbrainz::lookup(&disc_id).await
}

/// Albums of the library that carry this title by this artist, to warn before ripping a disc twice.
#[tauri::command]
async fn check_library(album_artist: String, album: String) -> Result<Vec<library_check::LibraryAlbum>, String> {
    let session = server::load().ok_or("Not connected to a server.")?;
    library_check::find(&session, &album_artist, &album).await
}

/// What the front end sends to tag one ripped file: the reviewed album, the track and where the cover is.
#[derive(serde::Deserialize)]
#[serde(rename_all = "camelCase")]
struct TagRequest {
    path: String,
    album_artist: String,
    album: String,
    year: String,
    disc_number: u32,
    disc_count: u32,
    track_total: u32,
    release_id: Option<String>,
    cover_url: Option<String>,
    position: u32,
    title: String,
    artist: String,
    recording_id: Option<String>,
    release_track_id: Option<String>,
}

/// Writes the tags and embeds the cover (downloaded from `cover_url`) into a ripped FLAC.
#[tauri::command]
async fn tag_file(request: TagRequest) -> Result<(), String> {
    let cover = match &request.cover_url {
        Some(url) => Some(tagging::Cover::from_bytes(musicbrainz::download(url).await?)?),
        None => None,
    };
    let album = tagging::AlbumTags {
        album_artist: request.album_artist,
        album: request.album,
        year: request.year,
        disc_number: request.disc_number,
        disc_total: request.disc_count,
        track_total: request.track_total,
        release_id: request.release_id,
    };
    let track = tagging::TrackTags {
        position: request.position,
        title: request.title,
        artist: request.artist,
        recording_id: request.recording_id,
        release_track_id: request.release_track_id,
    };
    tagging::write(Path::new(&request.path), &album, &track, cover.as_ref())
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_dialog::init())
        .invoke_handler(tauri::generate_handler![session_status, connect, sign_out, upload_file, lookup_disc, tag_file, check_library])
        .run(tauri::generate_context!())
        .expect("error while running the ripper");
}
