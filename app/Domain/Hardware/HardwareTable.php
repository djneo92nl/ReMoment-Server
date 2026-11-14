<?php

namespace App\Domain\Hardware;

use App\Domain\Device\Capabilities;
use App\Domain\Device\DeviceModels;

/**
 * The supported-hardware table of docs/user/hardware.md: brands and models of config/devices.php, their
 * capabilities from the driver contracts and the verification status of config/hardware-status.php.
 * Rendered into the page by `php artisan hardware:table`; nothing here is hand-maintained.
 */
final class HardwareTable
{
    public const START = '<!-- hardware-table:start -->';

    public const END = '<!-- hardware-table:end -->';

    public const UNKNOWN = ['status' => 'spec', 'date' => null, 'note' => 'No status recorded.'];

    /** @return array<string, string> status key => label */
    public static function statuses(): array
    {
        return config('hardware-status.statuses', []);
    }

    /** The status entry of a model: its own, else its driver's, else an unverified placeholder. */
    public static function statusFor(string $brand, string $model, ?string $driver): array
    {
        return config('hardware-status.models')[$brand][$model]
            ?? config('hardware-status.drivers')[$driver] ?? self::UNKNOWN;
    }

    /** @return list<array<string, mixed>> */
    public static function rows(): array
    {
        $rows = [];

        foreach (DeviceModels::all() as $brand => $models) {
            foreach ($models as $model => $definition) {
                $status = self::statusFor($brand, $model, $definition['driver'] ?? null);
                $extras = array_filter([
                    ($definition['wisa'] ?? false) ? 'wireless_speakers' : null,
                    ($definition['speaker'] ?? null) === 'external' ? 'wired_speakers' : null,
                ]);

                $rows[] = [
                    'brand' => $brand,
                    'model' => $model,
                    'driver' => $definition['driver_name'] ?? '',
                    'virtual' => (bool) ($definition['virtual'] ?? false),
                    'status' => $status['status'],
                    'status_label' => self::statuses()[$status['status']] ?? $status['status'],
                    'date' => $status['date'] ?? null,
                    'note' => $status['note'] ?? '',
                    'capabilities' => self::capabilities($definition['driver'] ?? null, $extras),
                ];
            }
        }

        return $rows;
    }

    /**
     * Capabilities of the driver's contracts. The hardware-bound ones (WiSA, wired speakers) only for the
     * models that declare them, as HardwareFeatures does.
     *
     * @param  string[]  $extras  hardware-bound capabilities this model has
     * @return string[]
     */
    private static function capabilities(?string $driver, array $extras): array
    {
        $hardwareBound = ['wireless_speakers', 'wired_speakers'];

        return array_values(array_filter(
            Capabilities::forDriver($driver),
            fn ($c) => !in_array($c, $hardwareBound, true) || in_array($c, $extras, true),
        ));
    }

    public static function json(): string
    {
        return json_encode(['statuses' => self::statuses(), 'devices' => self::rows()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    public static function markdown(): string
    {
        $out = ["Generated from `config/devices.php`, the driver contracts and `config/hardware-status.php` by `php artisan hardware:table`; do not edit between the markers.\n"];

        $out[] = "Status key:\n";
        foreach (self::statuses() as $label) {
            $out[] = '- '.$label;
        }
        $out[] = '';

        $byBrand = [];
        foreach (self::rows() as $row) {
            $byBrand[$row['brand']][] = $row;
        }

        foreach ($byBrand as $brand => $brandRows) {
            $out[] = "### {$brand}\n";
            $out[] = '| Model | Driver | Status | Checked | Note | What the server can do |';
            $out[] = '|---|---|---|---|---|---|';

            foreach ($brandRows as $r) {
                $out[] = sprintf(
                    '| %s | %s | %s | %s | %s | %s |',
                    self::cell($r['model'].($r['virtual'] ? ' (virtual)' : '')),
                    self::cell($r['driver']),
                    self::cell($r['status_label']),
                    $r['date'] ?? '—',
                    self::cell($r['note']),
                    self::cell(implode(', ', array_map(fn ($c) => "`{$c}`", $r['capabilities'])) ?: '—'),
                );
            }

            $out[] = '';
        }

        $out[] = 'Capabilities are what the driver offers when the device supports it; a device can list fewer (a generic UPnP renderer declares its own).';

        return implode("\n", $out)."\n";
    }

    /** The page with the generated block between the markers replaced; null when the markers are missing. */
    public static function render(string $page): ?string
    {
        $start = strpos($page, self::START);
        $end = strpos($page, self::END);

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return substr($page, 0, $start + strlen(self::START))."\n\n".self::markdown()."\n".substr($page, $end);
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $text);
    }
}
