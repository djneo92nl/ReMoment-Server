<?php

namespace App\Domain\Backup;

use App\Console\Commands\Database\ImportSqlite;
use App\Domain\Credentials\ServiceCredentials;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared rules of what a backup holds. */
final class Snapshot
{
    public const FORMAT = 1;

    /** Whole tables that only hold credentials; left out unless the backup is made with secrets. */
    public const SECRET_TABLES = ['users', 'admin_tokens', 'password_reset_tokens', 'personal_access_tokens'];

    /**
     * Safety net for settings keys holding OAuth tokens or API secrets (spotify_*_token, lastfm_session_key).
     * The credentials registry's `secret` fields are excluded by flag as well (ServiceCredentials::secretSettingKeys()).
     */
    public const SECRET_SETTING = '/(token|secret|session_key|password)/i';

    /** Not worth backing up: rebuilt by the app, or throwaway (same list as db:import-sqlite). */
    public static function skippedTables(): array
    {
        return array_merge(ImportSqlite::SKIP, ['failed_jobs']);
    }

    public static function family(string $driver): string
    {
        return in_array($driver, ['mysql', 'mariadb'], true) ? 'mysql' : $driver;
    }

    /** @return list<string> */
    public static function tables(Connection $db, bool $withSecrets): array
    {
        $skip = self::skippedTables();
        if (!$withSecrets) {
            $skip = array_merge($skip, self::SECRET_TABLES);
        }

        return collect($db->getSchemaBuilder()->getTables())->pluck('name')
            ->reject(fn ($t) => in_array($t, $skip, true) || str_starts_with($t, 'sqlite_'))->sort()->values()->all();
    }

    public static function isSecretSetting(string $key): bool
    {
        return in_array($key, ServiceCredentials::secretSettingKeys(), true) || preg_match(self::SECRET_SETTING, $key) === 1;
    }

    /** Drops or blanks credentials of a row; null when the row is left out altogether. */
    public static function redact(string $table, array $row, bool $withSecrets): ?array
    {
        if ($withSecrets) {
            return $row;
        }
        if ($table === 'settings' && self::isSecretSetting((string) ($row['key'] ?? ''))) {
            return null;
        }
        if ($table === 'clients') {
            // A restored panel registers again; the placeholder only satisfies the NOT NULL unique column.
            $row['api_token'] = null;
            $row['pairing_code'] = null;
            $row['registration_token'] = Str::random(40);
        }

        return $row;
    }

    public static function latestMigration(?Connection $db = null): ?string
    {
        $db ??= DB::connection();
        if (!$db->getSchemaBuilder()->hasTable('migrations')) {
            return null;
        }

        return $db->table('migrations')->max('migration');
    }

    /** Newest migration file this code base ships. */
    public static function latestMigrationFile(): ?string
    {
        $names = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);
        sort($names);

        return $names ? end($names) : null;
    }

    public static function encode(array $row): string
    {
        foreach ($row as $k => $v) {
            if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
                $row[$k] = ['$b64' => base64_encode($v)];
            }
        }

        return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function decode(string $line): array
    {
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        foreach ($row as $k => $v) {
            if (is_array($v) && isset($v['$b64'])) {
                $row[$k] = base64_decode($v['$b64']);
            }
        }

        return $row;
    }
}
