<?php

namespace App\Domain\Diagnostics;

/**
 * Takes secrets and network identifiers out of text before it goes into a diagnostics bundle (docs/architecture/diagnostics.md).
 * Applied to everything written to the zip, log lines included. There is no switch to turn it off.
 *
 * It removes: IPv4/IPv6 and MAC addresses, e-mail addresses, bearer/basic credentials, `rmt_` admin tokens, user:password@ in URLs,
 * `key=value` / `"key": "value"` pairs whose key names a secret, long opaque strings (hex, base64, JWT), and every literal secret value
 * it was told about (`.env` values of secret-looking keys, credential settings, APP_KEY, DB password).
 */
final class Redactor
{
    public const MASK = '[redacted]';

    /** Substrings that make a name a secret's name. */
    private const SECRET_SUB = 'pass(?:word|wd)?|secret|token|api[_-]?key|apikey|session[_-]?key|credential|authorization|cookie|signature|pairing[_-]?code';

    /** Short names that count only as a whole word (`key`, `app_key`), so `design` or `pinned` stay readable. */
    private const SECRET_WORD = '(?<![A-Za-z0-9])(?:key|auth|pin|sig|salt|dsn|bearer)(?![A-Za-z0-9])';

    private const SECRET_KEY = '(?:'.self::SECRET_SUB.'|'.self::SECRET_WORD.')';

    /** @var list<string> literal secret values, longest first */
    private array $literals = [];

    /** @param list<string|null> $literals */
    public function __construct(array $literals = [])
    {
        foreach ($literals as $literal) {
            $this->know($literal);
        }
    }

    /** A redactor that also knows this install's secrets: .env values of secret-looking keys, APP_KEY, the DB password, credential settings. */
    public static function forInstall(): self
    {
        $redactor = new self;

        foreach ([config('app.key'), config('database.connections.'.config('database.default').'.password'), config('mqtt.password'), config('services.spotify.client_secret'), config('services.lastfm.api_secret')] as $value) {
            $redactor->know(is_string($value) ? $value : null);
        }

        $env = base_path('.env');
        if (is_readable($env)) {
            foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/i', $line, $m) && preg_match('/'.self::SECRET_KEY.'/i', $m[1])) {
                    $redactor->know(trim($m[2], " \t\"'"));
                }
            }
        }

        try {
            foreach (\App\Domain\Credentials\ServiceCredentials::secretSettingKeys() as $key) {
                $value = \App\Models\Setting::get($key);
                $redactor->know(is_string($value) ? $value : null);
            }
            foreach (\App\Models\Setting::all() as $setting) {
                if (preg_match('/(token|secret|session_key|password)/i', $setting->key) && is_string($setting->value)) {
                    $redactor->know($setting->value);
                }
            }
        } catch (\Throwable) {
        }

        return $redactor;
    }

    public function know(?string $value): void
    {
        if ($value === null || strlen($value) < 4 || in_array(strtolower($value), ['true', 'false', 'null', 'none', 'local', 'debug'], true)) {
            return;
        }
        $this->literals[$value] = $value;
        uasort($this->literals, fn ($a, $b) => strlen($b) <=> strlen($a));
    }

    public function text(string $text): string
    {
        foreach ($this->literals as $literal) {
            $text = str_replace($literal, self::MASK, $text);
            // The same value as it appears JSON-escaped or URL-encoded.
            $text = str_replace([trim((string) json_encode($literal), '"'), rawurlencode($literal)], self::MASK, $text);
        }

        $mask = self::MASK;
        $rules = [
            // user:password@host in a URL
            '#(?<=://)[^/\s:@]+:[^/\s@]+@#' => $mask.'@',
            // Authorization headers and bare bearer/basic credentials
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{6,}/i' => '$1 '.$mask,
            '/\brmt_[A-Za-z0-9]{8,}/' => $mask,
            // JSON "password": "value"
            '/("(?:[^"\\\\]*?'.self::SECRET_KEY.'[^"\\\\]*?)"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i' => '$1"'.$mask.'"',
            // key=value, key: value, ?key=value&
            '/\b([A-Za-z0-9_.-]*'.self::SECRET_KEY.'[A-Za-z0-9_.-]*)(\s*[=:]\s*)(?!\[redacted\])("[^"]*"|\'[^\']*\'|[^\s,;&"\'}\]]+)/i' => '$1$2'.$mask,
            // JWT
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]*/' => $mask,
            // MAC addresses
            '/\b[0-9A-Fa-f]{2}(?:[:-][0-9A-Fa-f]{2}){5}\b/' => '[mac]',
            // e-mail
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '[email]',
            // IPv4
            '/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d])/' => '[ip]',
            // Long opaque strings: hex, base64/url-safe tokens of 32+ chars containing a digit
            '/(?<![A-Za-z0-9+\/_-])(?=[A-Za-z0-9+\/_-]*\d)(?=[A-Za-z0-9+\/_-]*[A-Za-z])[A-Za-z0-9+\/_-]{32,}={0,2}(?![A-Za-z0-9+\/_-])/' => $mask,
            '/(?<![0-9A-Fa-f])[0-9A-Fa-f]{32,}(?![0-9A-Fa-f])/' => $mask,
        ];

        foreach ($rules as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        // IPv6: candidates of hex groups and colons, kept only when PHP agrees they are addresses.
        $text = preg_replace_callback('/(?<![A-Za-z0-9:])[0-9A-Fa-f:]*:[0-9A-Fa-f:]*:[0-9A-Fa-f:.]*(?![A-Za-z0-9:])/', fn ($m) => filter_var(rtrim($m[0], ':') ?: $m[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) || filter_var($m[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[ip]' : $m[0], $text) ?? $text;

        return $text;
    }

    /** Redacts every string in a structure; values under secret-looking keys go entirely. */
    public function value(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && is_scalar($value) && $value !== '' && preg_match('/'.self::SECRET_KEY.'/i', $key)) {
            return self::MASK;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->value($v, is_string($k) ? $k : null);
            }

            return $out;
        }

        return is_string($value) ? $this->text($value) : $value;
    }
}
