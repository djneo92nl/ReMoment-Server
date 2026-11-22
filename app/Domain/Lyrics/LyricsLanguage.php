<?php

namespace App\Domain\Lyrics;

/**
 * The language of lyrics, detected locally (roadmap 12.8, docs/api/lyrics.md): no service, no model. Non-Latin scripts are told
 * by their letters; Latin text by how many of its words are common words of each language. Short or mixed text gives null
 * instead of a guess. Codes are ISO 639-1.
 */
final class LyricsLanguage
{
    /** Names of the codes this detector can answer, for the filters. */
    public const NAMES = [
        'en' => 'English', 'nl' => 'Dutch', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish', 'it' => 'Italian', 'pt' => 'Portuguese',
        'sv' => 'Swedish', 'da' => 'Danish', 'no' => 'Norwegian', 'pl' => 'Polish',
        'ru' => 'Russian', 'uk' => 'Ukrainian', 'ja' => 'Japanese', 'ko' => 'Korean', 'zh' => 'Chinese', 'ar' => 'Arabic',
        'el' => 'Greek', 'he' => 'Hebrew', 'th' => 'Thai', 'hi' => 'Hindi',
    ];

    /** Fewest words of a Latin text that are judged. */
    private const MIN_WORDS = 12;

    /** Share of the words a language's common words must reach, and how far ahead of the next language it must be. */
    private const MIN_SHARE = 0.10;

    private const MARGIN = 1.35;

    /** Letters of these scripts make the language, when they are at least this share of the letters. */
    private const SCRIPTS = ['ru' => '\p{Cyrillic}', 'ko' => '\p{Hangul}', 'ar' => '\p{Arabic}', 'el' => '\p{Greek}', 'he' => '\p{Hebrew}', 'th' => '\p{Thai}', 'hi' => '\p{Devanagari}'];

    /** Languages so close that a small lead between them is not told apart by common words. */
    private const SIBLINGS = ['da' => 'no', 'no' => 'da'];

    /** Distinctive common words per language: words several languages share ("de", "que", "me", "no") are left out. */
    private const WORDS = [
        'en' => "the and you your my that with this but are just when not all we they have what know don't i'm it's like got can will for from there into been were would could about when still never every",
        'nl' => 'het een ik jij jou mijn niet maar dat wat ook zijn hij wij voor naar nog hoe geen weet ben wil alles nooit altijd van aan uit bij zo wel nou ons mij gaat even deze hebben kan zou',
        'de' => 'ich du und nicht der die das ist ein eine mit mich dich mir dir wir sie auf für dass wenn nur noch auch aber wie bin bist hab haben kein immer nie liebe mein dein zu den dem wird von',
        'fr' => "je il elle nous vous est pas les des une dans pour avec mais sur moi toi suis c'est j'ai plus tout comme sans être jamais toujours très cette ces mes ne ce l'amour qu'on aux",
        'es' => 'yo el los las pero muy quiero eres soy estoy todo siempre nunca cuando porque está hay sin más corazón mis tus mi con un y es tu te amor vida',
        'it' => 'io il gli che non sei sono è perché più molto tutto niente sempre mai amore cuore mio tuo mia tua questo anche ancora solo senza nel della delle nella ti ci mi ma come',
        'pt' => 'eu você não os é está tô meu minha mas com pra na nós ele isso esse tudo sempre muito mais só coração seu sua vou vai tem são também já ainda até então uma',
        'sv' => 'jag och att det är inte ett som på för med har men mig dig när ska vill bara alla aldrig alltid kärlek hjärta från till av',
        'da' => 'jeg og at er ikke som med har men mig dig når skal vil bare alle aldrig altid kærlighed hjerte hvad hvor fra af til det',
        'no' => 'jeg og å er ikke som med har men meg deg når skal vil bare alle aldri alltid kjærlighet hjerte hva hvor fra av til det',
        'pl' => 'nie że się jest ja ty mnie ale jak już tylko mój moja miłość serce czy kiedy dla bo wszystko razem tak',
    ];

    /** @var array<string, array<string, true>>|null */
    private static ?array $sets = null;

    /** The ISO 639-1 code, or null when the text is too short or too mixed to tell. */
    public static function detect(string $text): ?string
    {
        $text = mb_strtolower(trim($text));
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters < 20) {
            return null;
        }

        $kana = preg_match_all('/[\p{Hiragana}\p{Katakana}]/u', $text);
        $han = preg_match_all('/\p{Han}/u', $text);
        if (($kana + $han) / $letters >= 0.3) {
            return $kana > 0 ? 'ja' : 'zh';
        }
        foreach (self::SCRIPTS as $code => $class) {
            if (preg_match_all("/{$class}/u", $text) / $letters >= 0.4) {
                return $code === 'ru' && preg_match('/[іїєґ]/u', $text) ? 'uk' : $code;
            }
        }

        preg_match_all("/[\p{L}][\p{L}'’]*/u", str_replace('’', "'", $text), $matches);
        $words = $matches[0];
        if (count($words) < self::MIN_WORDS) {
            return null;
        }

        $scores = [];
        foreach (self::sets() as $code => $set) {
            $scores[$code] = count(array_filter($words, fn ($w) => isset($set[$w]))) / count($words);
        }
        arsort($scores);
        $codes = array_keys($scores);
        [$top, $second] = [$codes[0], $codes[1]];
        if ($scores[$top] < self::MIN_SHARE) {
            return null;
        }
        if ((self::SIBLINGS[$top] ?? null) === $second) {
            $second = $codes[2];   // Danish and Norwegian share most words: only the others must be left behind
        }

        return $scores[$top] >= $scores[$second] * self::MARGIN ? $top : null;
    }

    /** @return array<string, array<string, true>> */
    private static function sets(): array
    {
        return self::$sets ??= array_map(fn (string $words) => array_fill_keys(explode(' ', $words), true), self::WORDS);
    }
}
