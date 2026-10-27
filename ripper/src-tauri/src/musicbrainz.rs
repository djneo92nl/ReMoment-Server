//! Looks a disc up on MusicBrainz by its disc ID and Cover Art Archive for the front cover.
//! One request per lookup, well inside MusicBrainz's limit of one per second.

use reqwest::{Client, StatusCode};
use serde::Serialize;
use serde_json::Value;

#[derive(Serialize, Debug, PartialEq)]
pub struct Track {
    pub position: u32,
    pub title: String,
    pub artist: String,
    pub length_ms: Option<u64>,
    /// MusicBrainz ID of this track on this release.
    pub mbid: String,
    /// MusicBrainz ID of the recording; the server reads it from the file's tags as the track's `mbid`.
    pub recording_id: String,
}

#[derive(Serialize, Debug, PartialEq)]
pub struct Release {
    pub id: String,
    pub title: String,
    pub artist: String,
    pub date: Option<String>,
    pub country: Option<String>,
    pub label: Option<String>,
    /// Which disc of the release this is (1-based) and how many there are.
    pub disc_number: u32,
    pub disc_count: u32,
    pub cover_url: Option<String>,
    pub tracks: Vec<Track>,
}

/// MusicBrainz asks for a contact in the User-Agent. Set `REMOMENT_RIPPER_CONTACT` (a URL or an address)
/// when building a release; without it only the app name is sent.
fn user_agent() -> String {
    let version = env!("CARGO_PKG_VERSION");
    match option_env!("REMOMENT_RIPPER_CONTACT") {
        Some(contact) if !contact.is_empty() => format!("ReMomentRipper/{version} ( {contact} )"),
        _ => format!("ReMomentRipper/{version}"),
    }
}

/// A MusicBrainz disc ID is 28 characters of base64 with `.`, `_` and `-` instead of `+`, `/` and `=`.
pub fn valid_disc_id(id: &str) -> bool {
    id.len() == 28 && id.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '_' | '-'))
}

/// The releases that carry this disc ID: none when MusicBrainz doesn't know the disc.
pub async fn lookup(disc_id: &str) -> Result<Vec<Release>, String> {
    let disc_id = disc_id.trim();
    if !valid_disc_id(disc_id) {
        return Err("That is not a MusicBrainz disc ID (28 characters).".into());
    }

    let res = Client::new()
        .get(format!("https://musicbrainz.org/ws/2/discid/{disc_id}"))
        .query(&[("fmt", "json"), ("inc", "recordings+artist-credits+labels")])
        .header("User-Agent", user_agent())
        .send()
        .await
        .map_err(|e| format!("Can't reach MusicBrainz: {e}"))?;

    match res.status() {
        StatusCode::OK => {
            let body: Value = res.json().await.map_err(|e| format!("Unexpected answer from MusicBrainz: {e}"))?;
            Ok(parse(&body, disc_id))
        }
        StatusCode::NOT_FOUND => Ok(vec![]),
        StatusCode::SERVICE_UNAVAILABLE | StatusCode::TOO_MANY_REQUESTS => {
            Err("MusicBrainz is busy. Try again in a few seconds.".into())
        }
        status => Err(format!("MusicBrainz answered {status}.")),
    }
}

/// Downloads the cover art (Cover Art Archive answers with a redirect to the image).
pub async fn download(url: &str) -> Result<Vec<u8>, String> {
    let res = Client::new()
        .get(url)
        .header("User-Agent", user_agent())
        .send()
        .await
        .map_err(|e| format!("Can't download the cover: {e}"))?;
    if !res.status().is_success() {
        return Err(format!("The cover download answered {}.", res.status()));
    }
    res.bytes().await.map(|b| b.to_vec()).map_err(|e| format!("Can't download the cover: {e}"))
}

/// "Artist A feat. Artist B": the credited names with their join phrases.
fn credit(value: &Value) -> String {
    value
        .as_array()
        .map(|parts| {
            parts
                .iter()
                .map(|p| {
                    let name = p["name"].as_str().or_else(|| p["artist"]["name"].as_str()).unwrap_or_default();
                    format!("{name}{}", p["joinphrase"].as_str().unwrap_or_default())
                })
                .collect()
        })
        .unwrap_or_default()
}

fn text(value: &Value) -> Option<String> {
    value.as_str().filter(|s| !s.is_empty()).map(String::from)
}

fn parse(body: &Value, disc_id: &str) -> Vec<Release> {
    let mut releases = vec![];

    for release in body["releases"].as_array().into_iter().flatten() {
        let media = release["media"].as_array().cloned().unwrap_or_default();
        // The disc we hold is the medium that lists our disc ID; a box set has several media.
        let Some(index) = media
            .iter()
            .position(|m| m["discs"].as_array().is_some_and(|d| d.iter().any(|d| d["id"] == disc_id)))
        else {
            continue;
        };
        let medium = &media[index];
        let id = release["id"].as_str().unwrap_or_default().to_string();

        let tracks = medium["tracks"]
            .as_array()
            .into_iter()
            .flatten()
            .map(|t| Track {
                position: t["position"].as_u64().unwrap_or_default() as u32,
                title: t["title"].as_str().unwrap_or_default().to_string(),
                artist: credit(&t["artist-credit"]),
                length_ms: t["length"].as_u64(),
                mbid: t["id"].as_str().unwrap_or_default().to_string(),
                recording_id: t["recording"]["id"].as_str().unwrap_or_default().to_string(),
            })
            .collect();

        releases.push(Release {
            title: release["title"].as_str().unwrap_or_default().to_string(),
            artist: credit(&release["artist-credit"]),
            date: text(&release["date"]),
            country: text(&release["country"]),
            label: release["label-info"].as_array().and_then(|l| l.iter().find_map(|l| text(&l["label"]["name"]))),
            disc_number: medium["position"].as_u64().unwrap_or(index as u64 + 1) as u32,
            disc_count: media.len() as u32,
            cover_url: release["cover-art-archive"]["front"]
                .as_bool()
                .unwrap_or(false)
                .then(|| format!("https://coverartarchive.org/release/{id}/front-500")),
            id,
            tracks,
        });
    }

    releases
}

#[cfg(test)]
mod tests {
    use super::*;

    const DISC_ID: &str = "Wn8eRBtfLDfM0qjYPdxrz.Zjs_U-";

    fn fixture() -> Value {
        serde_json::from_str(include_str!("../fixtures/discid-response.json")).unwrap()
    }

    #[test]
    fn disc_ids_are_checked_before_asking() {
        assert!(valid_disc_id(DISC_ID));
        assert!(!valid_disc_id("too-short"));
        assert!(!valid_disc_id("Wn8eRBtfLDfM0qjYPdxrz Zjs_U-"));
    }

    #[test]
    fn a_release_becomes_tracks_with_credits_and_a_cover() {
        let releases = parse(&fixture(), DISC_ID);
        let release = &releases[0];

        assert_eq!(release.title, "Fiction");
        assert_eq!(release.artist, "Dark Tranquillity");
        assert_eq!(release.date.as_deref(), Some("2007-04-24"));
        assert_eq!(release.label.as_deref(), Some("Century Media"));
        assert_eq!((release.disc_number, release.disc_count), (1, 1));
        assert!(release.cover_url.as_deref().unwrap().ends_with("/front-500"));
        assert_eq!(release.tracks[0].title, "Nothing to No One");
        assert_eq!(release.tracks[0].position, 1);
        assert_eq!(release.tracks[0].length_ms, Some(250000));
        assert_eq!(release.tracks[0].mbid, "bb8bcd9a-95f9-3277-af3c-0fbba0bd462c");
        assert_eq!(release.tracks[0].recording_id, "373f9354-9f96-405b-9437-e8c3ae18321c");
    }

    #[test]
    fn a_disc_id_no_medium_lists_gives_no_release() {
        assert!(parse(&fixture(), "AAAAAAAAAAAAAAAAAAAAAAAAAAA-").is_empty());
    }
}
