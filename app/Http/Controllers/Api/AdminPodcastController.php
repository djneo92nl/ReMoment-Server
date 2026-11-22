<?php

namespace App\Http\Controllers\Api;

use App\Domain\Podcasts\InvalidFeedException;
use App\Domain\Podcasts\PodcastRefresher;
use App\Domain\Podcasts\PodcastRetention;
use App\Domain\Podcasts\Podcasts;
use App\Http\Controllers\Controller;
use App\Models\Podcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Managing podcast subscriptions for apps (docs/api/podcasts.md), admin token only. */
class AdminPodcastController extends Controller
{
    /** Subscribes by RSS address: the feed is fetched now, so a wrong address is a 422 and the first episodes are there. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['feed_url' => ['required', 'string', 'max:2000']]);

        try {
            [$podcast, $created] = Podcasts::subscribe($data['feed_url']);
        } catch (InvalidFeedException $e) {
            return response()->json(['error' => 'invalid_feed', 'message' => $e->getMessage()], 422);
        }

        if (!$created) {
            return response()->json(['error' => 'already_subscribed', 'message' => 'Already subscribed to this feed.', 'data' => $podcast->apiItem()], 409);
        }

        return response()->json(['data' => $podcast->apiItem()], 201);
    }

    /** `keep_episodes`: newest episodes kept (0 = all, null = the server default). */
    public function update(Request $request, Podcast $podcast): JsonResponse
    {
        $data = $request->validate(['keep_episodes' => ['present', 'nullable', 'integer', 'min:0', 'max:5000']]);

        $podcast->update(['keep_episodes' => $data['keep_episodes']]);
        PodcastRetention::apply($podcast);

        return response()->json(['data' => $podcast->refresh()->apiItem()]);
    }

    public function destroy(Podcast $podcast): JsonResponse
    {
        Podcasts::unsubscribe($podcast);

        return response()->json(['status' => 'ok']);
    }

    /** Checks the feed now. Asking again within `podcasts.manual_interval` minutes is `429` (the publisher is not hammered). */
    public function refresh(Podcast $podcast): JsonResponse
    {
        $wait = (int) config('podcasts.manual_interval');

        if ($podcast->last_checked_at !== null && $podcast->last_checked_at->gt(now()->subMinutes($wait))) {
            return response()->json(['error' => 'too_soon', 'message' => "This feed was checked less than {$wait} minutes ago."], 429);
        }

        $result = PodcastRefresher::refresh($podcast, force: true);

        return response()->json(['status' => $result, 'data' => $podcast->refresh()->apiItem()]);
    }
}
