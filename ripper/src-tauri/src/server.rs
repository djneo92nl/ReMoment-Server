//! The ReMoment server's admin API (docs/api/admin-auth.md): sign in, check the token, upload a file.
//! The token lives in the OS keychain (release builds); debug builds use a private file, see `store`.

use reqwest::{multipart, Body, Client, Response, StatusCode};
use serde::{Deserialize, Serialize};
use std::path::Path;
use tokio_util::io::ReaderStream;

#[derive(Serialize, Deserialize)]
pub struct Session {
    pub url: String,
    pub token: String,
}

#[derive(Serialize)]
pub struct Uploaded {
    pub path: String,
    pub track_id: Option<u64>,
}

pub fn load() -> Option<Session> {
    serde_json::from_str(&store::read()?).ok()
}

pub fn save(session: &Session) -> Result<(), String> {
    store::write(&serde_json::to_string(session).map_err(|e| e.to_string())?)
}

pub fn clear() {
    store::delete();
}

/// Release builds keep the token in the OS keychain.
#[cfg(not(debug_assertions))]
mod store {
    use keyring::Entry;

    const SERVICE: &str = "nl.remoment.ripper";
    const ACCOUNT: &str = "server";

    fn entry() -> Result<Entry, String> {
        Entry::new(SERVICE, ACCOUNT).map_err(|e| format!("Keychain unavailable: {e}"))
    }

    pub fn read() -> Option<String> {
        entry().ok()?.get_password().ok()
    }

    pub fn write(json: &str) -> Result<(), String> {
        entry()?.set_password(json).map_err(|e| format!("Could not store the token in the keychain: {e}"))
    }

    pub fn delete() {
        if let Ok(entry) = entry() {
            let _ = entry.delete_credential();
        }
    }
}

/// Debug builds (`tauri dev`) keep it in a file in the home folder instead. Every rebuild is a new unsigned
/// binary, and macOS asks for the keychain password again for each one. The file is readable by the user only.
#[cfg(debug_assertions)]
mod store {
    use std::path::PathBuf;

    fn path() -> Result<PathBuf, String> {
        let home = std::env::var_os("HOME").or_else(|| std::env::var_os("USERPROFILE")).ok_or("No home folder.")?;
        Ok(PathBuf::from(home).join(".remoment-ripper-dev").join("session.json"))
    }

    pub fn read() -> Option<String> {
        std::fs::read_to_string(path().ok()?).ok()
    }

    pub fn write(json: &str) -> Result<(), String> {
        let path = path()?;
        std::fs::create_dir_all(path.parent().unwrap()).map_err(|e| e.to_string())?;
        std::fs::write(&path, json).map_err(|e| format!("Could not store the dev token: {e}"))?;
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            std::fs::set_permissions(&path, std::fs::Permissions::from_mode(0o600)).map_err(|e| e.to_string())?;
        }
        Ok(())
    }

    pub fn delete() {
        if let Ok(path) = path() {
            let _ = std::fs::remove_file(path);
        }
    }
}

/// "remoment.local/" → "http://remoment.local"
fn normalize(url: &str) -> String {
    let url = url.trim().trim_end_matches('/');
    if url.starts_with("http://") || url.starts_with("https://") {
        url.to_string()
    } else {
        format!("http://{url}")
    }
}

/// No overall timeout: a lossless album track is a long upload.
fn client() -> Client {
    Client::new()
}

fn unreachable(base: &str, e: reqwest::Error) -> String {
    format!("Can't reach {base}: {e}")
}

/// The server's own message for a failed request (`message` or `error`), else the status.
async fn failure(res: Response) -> String {
    let status = res.status();
    let body: serde_json::Value = res.json().await.unwrap_or_default();
    body["message"]
        .as_str()
        .or_else(|| body["error"].as_str())
        .map(|m| m.to_string())
        .unwrap_or_else(|| format!("The server answered {status}."))
}

pub async fn login(url: &str, password: &str) -> Result<Session, String> {
    let base = normalize(url);
    let res = client()
        .post(format!("{base}/api/admin/login"))
        .json(&serde_json::json!({ "password": password, "device_name": "ReMoment Ripper" }))
        .send()
        .await
        .map_err(|e| unreachable(&base, e))?;

    match res.status() {
        StatusCode::OK => {
            #[derive(Deserialize)]
            struct Login {
                token: String,
            }
            let login: Login = res.json().await.map_err(|e| format!("Unexpected answer from {base}: {e}"))?;
            Ok(Session { url: base, token: login.token })
        }
        StatusCode::UNAUTHORIZED => Err("Wrong password.".into()),
        StatusCode::TOO_MANY_REQUESTS => Err("Too many wrong passwords. Wait a minute and try again.".into()),
        _ => Err(failure(res).await),
    }
}

/// Whether the stored token is still accepted (`GET /api/admin/me`); an unreachable server is an error, not a sign-out.
pub async fn check(session: &Session) -> Result<bool, String> {
    let res = client()
        .get(format!("{}/api/admin/me", session.url))
        .bearer_auth(&session.token)
        .send()
        .await
        .map_err(|e| unreachable(&session.url, e))?;

    match res.status() {
        StatusCode::OK => Ok(true),
        StatusCode::UNAUTHORIZED => Ok(false),
        _ => Err(failure(res).await),
    }
}

pub async fn revoke(session: &Session) -> Result<(), String> {
    client()
        .delete(format!("{}/api/admin/token", session.url))
        .bearer_auth(&session.token)
        .send()
        .await
        .map_err(|e| unreachable(&session.url, e))?;
    Ok(())
}

/// `POST /api/admin/files`: the file is streamed from disk, not read into memory.
pub async fn upload(session: &Session, path: &Path) -> Result<Uploaded, String> {
    let name = path
        .file_name()
        .and_then(|n| n.to_str())
        .ok_or("That path has no file name.")?
        .to_string();
    let file = tokio::fs::File::open(path).await.map_err(|e| format!("Can't read {name}: {e}"))?;
    let length = file.metadata().await.map_err(|e| e.to_string())?.len();

    let part = multipart::Part::stream_with_length(Body::wrap_stream(ReaderStream::new(file)), length).file_name(name);
    let res = client()
        .post(format!("{}/api/admin/files", session.url))
        .bearer_auth(&session.token)
        .header("Accept", "application/json")
        .multipart(multipart::Form::new().part("file", part))
        .send()
        .await
        .map_err(|e| unreachable(&session.url, e))?;

    match res.status() {
        StatusCode::CREATED => {
            let body: serde_json::Value = res.json().await.map_err(|e| e.to_string())?;
            Ok(Uploaded {
                path: body["file"]["path"].as_str().unwrap_or_default().to_string(),
                track_id: body["track_id"].as_u64(),
            })
        }
        StatusCode::UNAUTHORIZED => {
            clear();
            Err("The server signed this app out. Connect again.".into())
        }
        _ => Err(failure(res).await),
    }
}
