<?php

namespace App\Domain\Radio;

use App\Models\RadioStation;
use Illuminate\Support\Facades\Http;

/** Is a station's stream still there? A dead one that came from radio-browser.info is looked up again by its uuid: streams move. */
class StreamChecker
{
    public function __construct(private readonly RadioBrowser $browser) {}

    /** @return 'ok'|'moved'|'dead' */
    public function check(RadioStation $station): string
    {
        $url = $station->streamUrl();

        if ($url === null || $this->alive($url)) {
            return $this->mark($station, 'ok');
        }

        $uuid = $station->getMeta('radio_browser_uuid');
        $found = $uuid ? $this->browser->byUuid($uuid) : null;

        if ($found !== null && $found['url'] !== $url && $this->alive($found['url'])) {
            $station->setMeta('url', $found['url']);

            return $this->mark($station, 'moved');
        }

        return $this->mark($station, 'dead');
    }

    private function mark(RadioStation $station, string $result): string
    {
        $station->setMeta('stream_status', $result === 'dead' ? 'dead' : 'ok');
        $station->setMeta('stream_checked_at', now()->toIso8601String());

        return $result;
    }

    private function alive(string $url): bool
    {
        try {
            $response = Http::timeout(6)->withUserAgent(config('metadata.user_agent'))->withOptions(['stream' => true])->get($url);
            $ok = $response->successful();
            $response->toPsrResponse()->getBody()->close();

            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }
}
