<?php

namespace App\Livewire\Developer;

use App\Domain\Storage\Proxies;
use App\Domain\Storage\StreamUrl;
use App\Models\Media\Track;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

/** /settings/developer: the stream URL a speaker is given for a track and what the server sees when it asks for it. */
class StreamUrlTester extends Component
{
    public ?int $trackId = null;

    public string $profile = '';

    /** @var array<int, array{label: string, url: string, status: ?int, headers: array<string, string>, error: ?string}> */
    public array $probes = [];

    public function probe(): void
    {
        $track = $this->trackId ? Track::with('trackFiles.file')->find($this->trackId) : null;
        $this->probes = [];

        if (!$track) {
            return;
        }

        $profile = $this->profile !== '' && Proxies::known($this->profile) ? $this->profile : null;
        $extension = $track->trackFiles->pluck('file.extension')->filter()->first();

        $urls = StreamUrl::withProfile($profile, fn () => [
            'Plain (ASE, Sonos)' => StreamUrl::for($track->id),
            'With extension (UPnP renderers)' => StreamUrl::withExtension($track->id, $extension),
        ]);

        foreach ($urls as $label => $url) {
            foreach (['HEAD' => null, 'First KB (range)' => ['Range' => 'bytes=0-1023']] as $how => $headers) {
                $this->probes[] = $this->request("{$label} · {$how}", $url, $headers);
            }
        }
    }

    private function request(string $label, string $url, ?array $headers): array
    {
        try {
            $pending = Http::timeout(10)->withOptions(['allow_redirects' => false, 'stream' => true]);
            $response = $headers === null ? $pending->head($url) : $pending->withHeaders($headers)->get($url);
            $keep = ['content-type', 'content-length', 'content-range', 'accept-ranges', 'location', 'transfermode.dlna.org', 'contentfeatures.dlna.org'];

            return [
                'label' => $label,
                'url' => $url,
                'status' => $response->status(),
                'headers' => collect($keep)->mapWithKeys(fn ($h) => [$h => $response->header($h)])->filter()->all(),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return ['label' => $label, 'url' => $url, 'status' => null, 'headers' => [], 'error' => $e->getMessage()];
        }
    }

    public function render()
    {
        return view('livewire.developer.stream-url-tester', [
            'base' => StreamUrl::base(),
            'baseFrom' => config('app.stream_url') ? 'STREAM_BASE_URL' : (config('app.url') ? 'request host, else APP_URL' : 'url()'),
            'profiles' => Proxies::profiles(),
        ]);
    }
}
