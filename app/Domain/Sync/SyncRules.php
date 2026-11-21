<?php

namespace App\Domain\Sync;

use App\Domain\Export\ExportLoudness;
use Illuminate\Validation\ValidationException;

/**
 * The rule set of a sync target (docs/architecture/offline-sync.md section 2): a closed list of selections in priority
 * order, an export profile with options, a byte budget and a fill policy. `normalize()` validates and fills the defaults,
 * so what is stored and what the planner reads is always this shape. An unknown selection type or option is a `422`.
 *
 * ```
 * { selections: [{type: playlist|album|artist, id} | {type: favorites} | {type: recently_added, limit?, days?}
 *                | {type: most_played, limit?, days?}],
 *   profile: "mp3-192", options: {loudness: off|album|track, layout: tree|flat},
 *   budget: {bytes: ?int, reserve_percent: 0-50}, fill: all|priority|fill_rest, max_churn_percent: 0-100 }
 * ```
 */
final class SyncRules
{
    public const SELECTION_TYPES = ['playlist', 'album', 'artist', 'favorites', 'recently_added', 'most_played'];

    public const FILLS = ['all', 'priority', 'fill_rest'];

    public const MAX_SELECTIONS = 50;

    /** Options the plan mentions but nothing builds yet: refused instead of silently ignored. */
    private const NOT_BUILT = ['folder_cover', 'playlists'];

    /**
     * @param  array<string, mixed>|null  $rules
     * @return array{selections: list<array<string, mixed>>, profile: string, options: array{loudness: string, layout: string}, budget: array{bytes: ?int, reserve_percent: int}, fill: string, max_churn_percent: int}
     *
     * @throws ValidationException
     */
    public static function normalize(?array $rules): array
    {
        $rules ??= [];
        $errors = [];

        $selections = [];
        $raw = $rules['selections'] ?? [];
        if (!is_array($raw) || count($raw) > self::MAX_SELECTIONS) {
            $errors['rules.selections'] = 'selections must be a list of at most '.self::MAX_SELECTIONS.'.';
            $raw = [];
        }
        foreach (array_values($raw) as $i => $s) {
            $type = is_array($s) ? ($s['type'] ?? null) : null;
            if (!in_array($type, self::SELECTION_TYPES, true)) {
                $errors["rules.selections.{$i}.type"] = 'Unknown selection type; use '.implode(', ', self::SELECTION_TYPES).'.';

                continue;
            }
            $clean = ['type' => $type];
            if (in_array($type, ['playlist', 'album', 'artist'], true)) {
                if (!self::positive($s['id'] ?? null)) {
                    $errors["rules.selections.{$i}.id"] = "A {$type} selection needs an id.";

                    continue;
                }
                $clean['id'] = (int) $s['id'];
            }
            if (in_array($type, ['recently_added', 'most_played'], true)) {
                foreach (['limit' => 100000, 'days' => 3650] as $field => $max) {
                    if (($s[$field] ?? null) !== null) {
                        if (!self::positive($s[$field]) || (int) $s[$field] > $max) {
                            $errors["rules.selections.{$i}.{$field}"] = "{$field} must be a whole number from 1 to {$max}.";

                            continue 2;
                        }
                        $clean[$field] = (int) $s[$field];
                    }
                }
                if ($type === 'recently_added' && !isset($clean['limit']) && !isset($clean['days'])) {
                    $errors["rules.selections.{$i}.limit"] = 'recently_added needs a limit and/or days.';

                    continue;
                }
                $clean['limit'] ??= $type === 'most_played' ? 200 : null;
                $clean = array_filter($clean, fn ($v) => $v !== null);
            }
            $selections[] = $clean;
        }

        $profile = $rules['profile'] ?? config('export.default_profile');
        if (!is_string($profile) || !is_array(config("export.profiles.{$profile}"))) {
            $errors['rules.profile'] = 'Unknown profile.';
            $profile = (string) config('export.default_profile');
        }

        $options = is_array($rules['options'] ?? null) ? $rules['options'] : [];
        foreach (self::NOT_BUILT as $name) {
            if (!empty($options[$name])) {
                $errors["rules.options.{$name}"] = "{$name} is not built yet.";
            }
        }
        $loudness = $options['loudness'] ?? 'off';
        if (!in_array($loudness, ExportLoudness::MODES, true)) {
            $errors['rules.options.loudness'] = 'loudness is off, album or track.';
            $loudness = 'off';
        }
        $layout = $options['layout'] ?? 'tree';
        if (!in_array($layout, ['tree', 'flat'], true)) {
            $errors['rules.options.layout'] = 'layout is tree or flat.';
            $layout = 'tree';
        }
        if ($layout === 'flat' && (count($selections) !== 1 || $selections[0]['type'] !== 'playlist')) {
            $errors['rules.options.layout'] = 'The flat layout only works for exactly one playlist.';
        }

        $budget = is_array($rules['budget'] ?? null) ? $rules['budget'] : [];
        $bytes = $budget['bytes'] ?? null;
        if ($bytes !== null && !self::positive($bytes)) {
            $errors['rules.budget.bytes'] = 'bytes must be a positive whole number or null (no cap).';
            $bytes = null;
        }
        $reserve = $budget['reserve_percent'] ?? config('sync.reserve_percent');
        if (!is_numeric($reserve) || $reserve < 0 || $reserve > 50) {
            $errors['rules.budget.reserve_percent'] = 'reserve_percent is 0 to 50.';
            $reserve = (int) config('sync.reserve_percent');
        }

        $fill = $rules['fill'] ?? ($bytes === null ? 'all' : 'priority');
        if (!in_array($fill, self::FILLS, true)) {
            $errors['rules.fill'] = 'fill is all, priority or fill_rest.';
            $fill = 'all';
        }

        $churn = $rules['max_churn_percent'] ?? config('sync.max_churn_percent');
        if (!is_numeric($churn) || $churn < 0 || $churn > 100) {
            $errors['rules.max_churn_percent'] = 'max_churn_percent is 0 to 100 (0 = unlimited).';
            $churn = (int) config('sync.max_churn_percent');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'selections' => $selections,
            'profile' => $profile,
            'options' => ['loudness' => $loudness, 'layout' => $layout],
            'budget' => ['bytes' => $bytes === null ? null : (int) $bytes, 'reserve_percent' => (int) $reserve],
            'fill' => $fill,
            'max_churn_percent' => (int) $churn,
        ];
    }

    private static function positive(mixed $value): bool
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0;
    }
}
