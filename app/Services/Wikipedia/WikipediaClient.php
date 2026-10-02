<?php

namespace App\Services\Wikipedia;

use Illuminate\Support\Facades\Http;

/** English Wikipedia summaries, found through a Wikidata id (what MusicBrainz links to). */
class WikipediaClient
{
    /**
     * The lead summary of the article for a Wikidata item, or null when it has none (or is a disambiguation
     * page). A rate limit or outage throws, so the calling job is retried.
     *
     * @return array{extract: string, url: string|null}|null
     */
    public function summaryForWikidata(string $wikidataId): ?array
    {
        $entity = $this->get("https://www.wikidata.org/wiki/Special:EntityData/{$wikidataId}.json");
        $title = $entity?->json("entities.{$wikidataId}.sitelinks.enwiki.title");

        if (!$title) {
            return null;
        }

        $summary = $this->get('https://en.wikipedia.org/api/rest_v1/page/summary/'.rawurlencode(str_replace(' ', '_', $title)));
        $extract = trim((string) $summary?->json('extract'));

        if ($summary === null || $extract === '' || $summary->json('type') === 'disambiguation') {
            return null;
        }

        return ['extract' => $extract, 'url' => $summary->json('content_urls.desktop.page')];
    }

    private function get(string $url): ?\Illuminate\Http\Client\Response
    {
        $response = Http::withHeaders(['User-Agent' => 'ReMoment/1.0 (remko@pionect.nl)'])->get($url);

        if ($response->status() === 429 || $response->serverError()) {
            $response->throw();
        }

        return $response->ok() ? $response : null;
    }
}
