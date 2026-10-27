//! Writes the tags the server reads (`app/Domain/Storage/AudioTags.php`) into a ripped FLAC, with the cover
//! embedded: the server takes artwork only from inside the file.
//!
//! MusicBrainz IDs follow Picard's convention: `MUSICBRAINZ_TRACKID` is the *recording*, 
//! `MUSICBRAINZ_RELEASETRACKID` the track on this release, `MUSICBRAINZ_ALBUMID` the release.

use lofty::config::WriteOptions;
use lofty::file::TaggedFileExt;
use lofty::picture::{MimeType, Picture, PictureType};
use lofty::prelude::*;
use lofty::tag::{Tag, TagType};
use std::path::Path;

/// What every track of the disc shares.
pub struct AlbumTags {
    pub album_artist: String,
    pub album: String,
    pub year: String,
    pub disc_number: u32,
    pub disc_total: u32,
    pub track_total: u32,
    pub release_id: Option<String>,
}

pub struct TrackTags {
    pub position: u32,
    pub title: String,
    pub artist: String,
    pub recording_id: Option<String>,
    pub release_track_id: Option<String>,
}

pub struct Cover {
    pub mime: MimeType,
    pub data: Vec<u8>,
}

impl Cover {
    /// A JPEG or PNG, told apart by its first bytes (the server only takes those, JPEG, PNG, GIF and WebP, and we keep to two).
    pub fn from_bytes(data: Vec<u8>) -> Result<Cover, String> {
        let mime = if data.starts_with(&[0xFF, 0xD8, 0xFF]) {
            MimeType::Jpeg
        } else if data.starts_with(&[0x89, b'P', b'N', b'G']) {
            MimeType::Png
        } else {
            return Err("The cover is not a JPEG or PNG image.".into());
        };
        Ok(Cover { mime, data })
    }
}

fn set(tag: &mut Tag, key: ItemKey, value: &str) {
    if !value.trim().is_empty() {
        tag.insert_text(key, value.trim().to_string());
    }
}

/// Adds the tags to the FLAC at `path`, keeping what the ripper already wrote (ReplayGain, accuracy log...).
pub fn write(path: &Path, album: &AlbumTags, track: &TrackTags, cover: Option<&Cover>) -> Result<(), String> {
    let mut file = lofty::read_from_path(path).map_err(|e| format!("Can't read {}: {e}", path.display()))?;

    if file.primary_tag().is_none() {
        file.insert_tag(Tag::new(TagType::VorbisComments));
    }
    let tag = file.primary_tag_mut().ok_or("This file has no place for tags.")?;

    set(tag, ItemKey::TrackTitle, &track.title);
    set(tag, ItemKey::TrackArtist, &track.artist);
    set(tag, ItemKey::AlbumTitle, &album.album);
    set(tag, ItemKey::AlbumArtist, &album.album_artist);
    set(tag, ItemKey::RecordingDate, &album.year);
    tag.set_track(track.position);
    tag.set_track_total(album.track_total);
    tag.set_disk(album.disc_number);
    tag.set_disk_total(album.disc_total);
    set(tag, ItemKey::MusicBrainzRecordingId, track.recording_id.as_deref().unwrap_or_default());
    set(tag, ItemKey::MusicBrainzTrackId, track.release_track_id.as_deref().unwrap_or_default());
    set(tag, ItemKey::MusicBrainzReleaseId, album.release_id.as_deref().unwrap_or_default());

    if let Some(cover) = cover {
        tag.remove_picture_type(PictureType::CoverFront);
        tag.push_picture(Picture::unchecked(cover.data.clone()).pic_type(PictureType::CoverFront).mime_type(cover.mime.clone()).build());
    }

    tag.save_to_path(path, WriteOptions::default())
        .map_err(|e| format!("Can't write the tags to {}: {e}", path.display()))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn copy_fixture(name: &str) -> std::path::PathBuf {
        let dir = std::env::temp_dir().join(format!("remoment-ripper-{name}-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.join("track.flac");
        std::fs::copy(concat!(env!("CARGO_MANIFEST_DIR"), "/fixtures/silence.flac"), &path).unwrap();
        path
    }

    fn album() -> AlbumTags {
        AlbumTags { album_artist: "Dark Tranquillity".into(), album: "Fiction".into(), year: "2007".into(), disc_number: 1, disc_total: 2, track_total: 12, release_id: Some("a060c352-c9d6-4717-aab8-95a2965fa8d7".into()) }
    }

    fn track() -> TrackTags {
        TrackTags { position: 3, title: "Terminus".into(), artist: "Dark Tranquillity feat. Someone".into(), recording_id: Some("373f9354-9f96-405b-9437-e8c3ae18321c".into()), release_track_id: Some("bb8bcd9a-95f9-3277-af3c-0fbba0bd462c".into()) }
    }

    #[test]
    fn tags_and_front_cover_are_read_back() {
        let path = copy_fixture("tags");
        let cover = Cover::from_bytes(std::fs::read(concat!(env!("CARGO_MANIFEST_DIR"), "/fixtures/cover.jpg")).unwrap()).unwrap();
        write(&path, &album(), &track(), Some(&cover)).unwrap();

        let file = lofty::read_from_path(&path).unwrap();
        let tag = file.primary_tag().unwrap();
        assert_eq!(tag.title().as_deref(), Some("Terminus"));
        assert_eq!(tag.artist().as_deref(), Some("Dark Tranquillity feat. Someone"));
        assert_eq!(tag.album().as_deref(), Some("Fiction"));
        assert_eq!(tag.get_string(ItemKey::AlbumArtist), Some("Dark Tranquillity"));
        assert_eq!((tag.track(), tag.track_total(), tag.disk(), tag.disk_total()), (Some(3), Some(12), Some(1), Some(2)));
        assert_eq!(tag.get_string(ItemKey::MusicBrainzRecordingId), Some("373f9354-9f96-405b-9437-e8c3ae18321c"));
        assert_eq!(tag.get_string(ItemKey::MusicBrainzTrackId), Some("bb8bcd9a-95f9-3277-af3c-0fbba0bd462c"));
        assert_eq!(tag.pictures().len(), 1);
        assert_eq!(tag.pictures()[0].pic_type(), PictureType::CoverFront);
        assert_eq!(tag.pictures()[0].mime_type(), Some(&MimeType::Jpeg));

        // Lets the server's own reader check this exact file (see ripper/README.md).
        if let Ok(keep) = std::env::var("RIPPER_KEEP_TAGGED") {
            std::fs::copy(&path, keep).unwrap();
        }
        std::fs::remove_dir_all(path.parent().unwrap()).unwrap();
    }

    #[test]
    fn writing_twice_keeps_one_cover() {
        let path = copy_fixture("twice");
        let cover = Cover::from_bytes(std::fs::read(concat!(env!("CARGO_MANIFEST_DIR"), "/fixtures/cover.jpg")).unwrap()).unwrap();
        write(&path, &album(), &track(), Some(&cover)).unwrap();
        write(&path, &album(), &track(), Some(&cover)).unwrap();

        assert_eq!(lofty::read_from_path(&path).unwrap().primary_tag().unwrap().pictures().len(), 1);
        std::fs::remove_dir_all(path.parent().unwrap()).unwrap();
    }

    #[test]
    fn a_cover_must_be_jpeg_or_png() {
        assert!(Cover::from_bytes(b"<html>not found</html>".to_vec()).is_err());
        assert!(Cover::from_bytes(vec![0x89, b'P', b'N', b'G', 0, 0]).is_ok());
    }
}
