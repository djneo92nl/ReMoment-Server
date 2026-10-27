//! Is this album already in the library? Asked before ripping, by name: album artist and title, among the
//! albums with an own file (`scope=local`). A name match is a warning, not a guarantee.

use crate::server::Session;
use reqwest::Client;
use serde::Serialize;
use serde_json::Value;

#[derive(Serialize, Debug, PartialEq)]
pub struct LibraryAlbum {
    pub id: u64,
    pub name: String,
    pub artist: String,
    pub year: Option<u64>,
    pub track_count: Option<usize>,
}

/// "The Dark Side of the Moon" and "dark side of the moon!" compare equal; so do "&" and "and".
fn key(text: &str) -> String {
    let lower = text.to_lowercase().replace('&', "and");
    let lower = lower.strip_prefix("the ").unwrap_or(&lower);
    lower.chars().filter(|c| c.is_alphanumeric()).collect()
}

fn album_item(item: &Value) -> Option<LibraryAlbum> {
    Some(LibraryAlbum {
        id: item["id"].as_u64()?,
        name: item["name"].as_str()?.to_string(),
        artist: item["artist_name"].as_str().unwrap_or_default().to_string(),
        year: item["year"].as_u64(),
        track_count: None,
    })
}

/// The albums in `items` (the API's AlbumItem list) that carry this title; `artist` of `None` accepts any artist
/// (an artist's own album list has no artist on each item).
fn same_title(items: &Value, artist: Option<&str>, title: &str) -> Vec<LibraryAlbum> {
    items
        .as_array()
        .into_iter()
        .flatten()
        .filter_map(album_item)
        .filter(|a| key(&a.name) == key(title))
        .filter(|a| artist.is_none_or(|artist| key(&a.artist) == key(artist)))
        .collect()
}

async fn get(client: &Client, session: &Session, path: &str, query: &[(&str, &str)]) -> Result<Value, String> {
    let res = client
        .get(format!("{}/api/library/{path}", session.url))
        .query(query)
        .header("Accept", "application/json")
        .send()
        .await
        .map_err(|e| format!("Can't reach {}: {e}", session.url))?;
    if !res.status().is_success() {
        return Err(format!("The server answered {} for the library check.", res.status()));
    }
    res.json().await.map_err(|e| format!("Unexpected answer from the server: {e}"))
}

/// Looks the album up two ways, since a search is capped (15 albums by name): by its title, and through its
/// artist, whose page lists every album. The union is returned with each album's track count.
pub async fn find(session: &Session, album_artist: &str, album: &str) -> Result<Vec<LibraryAlbum>, String> {
    let (artist, title) = (album_artist.trim(), album.trim());
    if title.chars().count() < 2 {
        return Ok(vec![]); // the search wants at least two characters
    }
    let client = Client::new();
    let mut found: Vec<LibraryAlbum> = vec![];

    let by_title = get(&client, session, "search", &[("q", title), ("scope", "local")]).await?;
    found.extend(same_title(&by_title["albums"], Some(artist), title));

    if artist.chars().count() >= 2 {
        let by_artist = get(&client, session, "search", &[("q", artist), ("scope", "local")]).await?;
        for candidate in by_artist["artists"].as_array().into_iter().flatten() {
            let (Some(id), Some(name)) = (candidate["id"].as_u64(), candidate["name"].as_str()) else { continue };
            if key(name) != key(artist) {
                continue;
            }
            let page = get(&client, session, &format!("artists/{id}"), &[("scope", "local")]).await?;
            for mut hit in same_title(&page["albums"], None, title) {
                hit.artist = name.to_string();
                found.push(hit);
            }
        }
    }

    found.sort_by_key(|a| a.id);
    found.dedup_by_key(|a| a.id);

    for hit in &mut found {
        if let Ok(detail) = get(&client, session, &format!("albums/{}", hit.id), &[("scope", "local")]).await {
            hit.track_count = detail["tracks"].as_array().map(|t| t.len());
        }
    }
    Ok(found)
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn names_compare_without_case_punctuation_the_or_ampersands() {
        assert_eq!(key("The Dark Side of the Moon"), key("dark side of the moon!"));
        assert_eq!(key("Simon & Garfunkel"), key("Simon and Garfunkel"));
        assert_ne!(key("Fiction"), key("Fiction (Live)"));
    }

    #[test]
    fn only_the_same_artist_and_title_match() {
        let items = json!([
            { "id": 1, "name": "Fiction", "artist_name": "Dark Tranquillity", "year": 2007 },
            { "id": 2, "name": "Fiction", "artist_name": "Someone Else", "year": 1999 },
            { "id": 3, "name": "Fiction Deluxe", "artist_name": "Dark Tranquillity", "year": 2007 },
        ]);

        let hits = same_title(&items, Some("dark tranquillity"), "fiction");
        assert_eq!(hits.iter().map(|a| a.id).collect::<Vec<_>>(), vec![1]);
        assert_eq!(hits[0].year, Some(2007));
    }

    #[test]
    fn an_artist_page_has_no_artist_on_its_albums() {
        let items = json!([{ "id": 7, "name": "Fiction", "year": null }, { "id": 8, "name": "Other", "year": 2001 }]);

        assert_eq!(same_title(&items, None, "Fiction").len(), 1);
        assert!(same_title(&json!(null), None, "Fiction").is_empty());
    }

    /// Against a real server: `REMOMENT_URL=http://remomentserver.test cargo test -- --ignored live`
    #[tokio::test]
    #[ignore]
    async fn live_server_finds_an_album_it_has() {
        let session = Session { url: std::env::var("REMOMENT_URL").expect("set REMOMENT_URL"), token: String::new() };

        let hits = find(&session, "Billie Eilish", "Hit Me Hard and Soft").await.unwrap();
        assert_eq!(hits.len(), 1, "{hits:?}");
        assert_eq!(hits[0].year, Some(2024));
        assert!(hits[0].track_count.unwrap() >= 1);

        assert!(find(&session, "Billie Eilish", "No Such Album At All").await.unwrap().is_empty());
        assert!(find(&session, "Nobody", "Hit Me Hard and Soft").await.unwrap().is_empty());
    }
}
