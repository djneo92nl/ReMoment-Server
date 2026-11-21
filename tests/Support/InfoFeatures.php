<?php

namespace Tests\Support;

use App\Domain\Analysis\Tools;
use App\Domain\Export\ExportFormats;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * The `features` of GET /api/info in the Full profile of the test environment: the keys that are true wherever
 * the test does not switch something on or off (App\Domain\System\ServerFeatures), so a test names only what it varies.
 */
final class InfoFeatures
{
    /** @return array<string, mixed> true in the Full profile with nothing else configured; export only where this machine has ffmpeg */
    public static function baseline(): array
    {
        return [
            'discover' => true,
            'rip' => true,
            'sleep_timer' => true,
            'nodes' => true,
            'sub_devices' => true,
        ] + (Tools::available(Tools::ffmpeg()) ? ['export' => true, 'export_formats' => ExportFormats::availableIds()] : []);
    }

    /** Asserts the whole `features` object (key order is not part of the contract). @param array<string, mixed> $varying */
    public static function assertFeatures(TestResponse $response, array $varying): void
    {
        $expected = $varying + self::baseline();
        $actual = $response->json('features');
        ksort($expected);
        ksort($actual);

        Assert::assertSame($expected, $actual);
    }
}
