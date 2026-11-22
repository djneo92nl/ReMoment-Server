<?php

namespace App\Domain\Lyrics;

/**
 * An explicit flag guessed from the lyrics, for tracks whose source gave none (roadmap 12.8). A list of strong swear words
 * (English, Dutch, German, French, Spanish, Italian, Portuguese), whole words only; mild words (damn, hell, ass) are not on it.
 * It is a guess: a clean flag is never derived from it, only a positive one.
 */
final class LyricsExplicit
{
    /** Regex fragments of whole words; `\w*` lets a stem take its endings. */
    private const WORDS = [
        // English
        'fuck\w*', 'motherfuck\w*', 'shit\w*', 'bullshit', 'bitch\w*', 'cunt\w*', 'asshole\w*', 'dickhead', 'cocksuck\w*', 'nigga\w*', 'nigger\w*', 'whore\w*', 'slut\w*', 'wanker\w*', 'pussy', 'pussies',
        // Dutch
        'kut', 'kutwijf', 'klootzak\w*', 'hoer', 'hoeren', 'tering\w*', 'godverdomme', 'lul', 'neuk\w*', 'kankerhoer', 'flikker\w*',
        // German
        'scheiße', 'scheisse', 'ficken', 'fick\w*', 'arschloch\w*', 'hurensohn\w*', 'fotze\w*', 'wichser\w*', 'schlampe\w*',
        // French
        'putain', 'merde', 'connard\w*', 'connasse', 'salope\w*', 'enculé\w*', 'enculer', 'bordel', 'nique\w*', 'pute',
        // Spanish
        'puta', 'putas', 'mierda', 'joder', 'coño', 'cabrón', 'cabron', 'pendejo\w*', 'hijueputa', 'chingar\w*', 'verga',
        // Italian
        'cazzo', 'merda', 'stronzo\w*', 'puttana', 'vaffanculo', 'minchia', 'troia',
        // Portuguese
        'caralho', 'foder', 'foda-se', 'buceta', 'viado',
    ];

    private static ?string $pattern = null;

    public static function in(string $text): bool
    {
        self::$pattern ??= '/(?<![\p{L}\p{N}])(?:'.implode('|', self::WORDS).')(?![\p{L}\p{N}])/iu';

        return preg_match(self::$pattern, $text) === 1;
    }
}
