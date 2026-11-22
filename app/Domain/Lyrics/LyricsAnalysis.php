<?php

namespace App\Domain\Lyrics;

use App\Domain\Library\Enrichment;
use App\Models\Media\Metadata;
use App\Models\Media\Track;

/**
 * What the server works out from a track's stored lyrics (roadmap 12.8, docs/api/lyrics.md): their language and, when the
 * source gave no explicit flag, a guess at one. Both are track metadata with the source `lyrics`; written when the lyrics job
 * stores lyrics and by `library:lyrics-analysis` for the lyrics stored before this existed. No request leaves the server.
 */
final class LyricsAnalysis
{
    public const SOURCE = 'lyrics';

    public const LANGUAGE = 'lyrics_language';

    /** The text of the lyrics without LRC time tags, plain lyrics first; null when the track has none. */
    public static function text(Track $track): ?string
    {
        $plain = $track->lyricsPlain();
        $synced = $track->lyricsSynced();
        $text = ($plain !== null && trim($plain) !== '') ? $plain : ($synced ? preg_replace('/\[\d+:\d+(?:[.:]\d+)?\]\s*/u', '', $synced) : null);

        return $text !== null && trim($text) !== '' ? $text : null;
    }

    /** Stores the language (empty when it cannot be told, so the track is not looked at again) and a positive explicit guess. */
    public static function apply(Track $track): void
    {
        $text = self::text($track);
        if ($text === null) {
            return;
        }

        Enrichment::save($track, self::LANGUAGE, LyricsLanguage::detect($text) ?? '', 'string', self::SOURCE);

        // Another source's explicit flag (Spotify) always wins over the guess.
        $given = $track->metadata()->where('key', 'explicit')->where('source', '!=', self::SOURCE)->exists();
        if ($given || !LyricsExplicit::in($text)) {
            Metadata::query()->where('metadatable_type', $track->getMorphClass())->where('metadatable_id', $track->id)
                ->where('key', 'explicit')->where('source', self::SOURCE)->delete();

            return;
        }
        Enrichment::save($track, 'explicit', '1', 'bool', self::SOURCE);
    }

    /** The language code stored for a track, or null when unknown. */
    public static function language(Track $track): ?string
    {
        $value = $track->metadata()->where('key', self::LANGUAGE)->value('value');

        return $value !== null && $value !== '' ? $value : null;
    }

    public static function name(?string $code): ?string
    {
        return $code === null ? null : (LyricsLanguage::NAMES[$code] ?? strtoupper($code));
    }
}
