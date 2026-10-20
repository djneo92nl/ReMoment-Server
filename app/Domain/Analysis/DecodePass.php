<?php

namespace App\Domain\Analysis;

use App\Models\File;
use App\Models\FileAnalysis;
use Illuminate\Support\Facades\Process;

/**
 * One ffmpeg run that decodes a file once and feeds every given stage's filter branch (`asplit`), instead of
 * one decode per stage. Holds the log (`$stderr`) and the temp files the branches wrote; call `cleanup()` when
 * the stages have read them.
 */
final class DecodePass
{
    /** @param array<string, string> $files stage key => temp file */
    private function __construct(public readonly string $stderr, private array $files) {}

    /**
     * @param  list<DecodedStage>  $stages
     *
     * @throws \RuntimeException when ffmpeg fails (the stages then run on their own, so each gets its own error)
     */
    public static function run(array $stages, File $file, string $localPath): self
    {
        $stages = array_values($stages);
        $count = count($stages);
        $graph = $count > 1
            ? ['[0:a]asplit='.$count.implode('', array_map(fn ($i) => "[i{$i}]", array_keys($stages))).';']
            : [];
        $outputs = [];
        $files = [];

        foreach ($stages as $i => $stage) {
            $branch = $stage->branch($file);
            $graph[] = str_replace(['{in}', '{out}'], [$count > 1 ? "[i{$i}]" : '[0:a]', "[o{$i}]"], $branch->graph).($i < $count - 1 ? ';' : '');

            $target = '-';
            if ($branch->output !== null) {
                $target = $files[$stage->key()] = tempnam(sys_get_temp_dir(), 'remoment-decode-');
            }
            array_push($outputs, '-map', "[o{$i}]", ...[...($branch->output ?? ['-f', 'null']), $target]);
        }

        $result = Process::timeout((int) config('analysis.timeout'))->run([
            Tools::ffmpeg(), '-y', '-hide_banner', '-nostats', '-i', $localPath, '-vn', '-filter_complex', implode('', $graph), ...$outputs,
        ]);

        $pass = new self($result->errorOutput(), $files);

        if (!$result->successful()) {
            $pass->cleanup();

            throw new \RuntimeException('ffmpeg could not decode the file: '.self::lastLine($result->errorOutput()));
        }

        return $pass;
    }

    /** One stage on its own decode (`--stage=…`, a lone stage, or the fallback after a failed shared run). */
    public static function runAlone(DecodedStage $stage, File $file, string $localPath, FileAnalysis $analysis): array
    {
        $pass = self::run([$stage], $file, $localPath);

        try {
            return $stage->fromDecode($file, $localPath, $analysis, $pass);
        } finally {
            $pass->cleanup();
        }
    }

    /** The temp file a stage's branch wrote its audio to. */
    public function file(string $key): string
    {
        return $this->files[$key] ?? throw new \LogicException("No decode output for stage {$key}.");
    }

    public function cleanup(): void
    {
        foreach ($this->files as $path) {
            @unlink($path);
        }

        $this->files = [];
    }

    private static function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));

        return end($lines) ?: 'no output';
    }
}
