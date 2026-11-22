<?php

namespace App\Http\Controllers\Api;

use App\Domain\Podcasts\DeviceUnreachable;
use App\Domain\Podcasts\EpisodePlayback;
use App\Domain\Podcasts\Podcasts;
use App\Http\Controllers\Controller;
use App\Integrations\Common\UnsupportedOperationException;
use App\Models\Device;
use App\Models\PlaybackPosition;
use App\Models\Podcast;
use App\Models\PodcastEpisode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Podcasts (workstream 8.2, docs/api/podcasts.md). Reading, playing and marking played are open like playback itself;
 * adding, removing and refreshing a subscription need the admin token (AdminPodcastController).
 */
class PodcastController extends Controller
{
    public function index(): JsonResponse
    {
        $podcasts = Podcast::query()
            ->withCount(['episodes', 'episodes as unplayed_count' => fn ($q) => $q->whereNull('played_at')])
            ->withMax('episodes as latest_episode_at', 'published_at')
            ->orderBy('title')->get();

        return response()->json(['data' => $podcasts->map(fn (Podcast $p) => $p->apiItem())->values()]);
    }

    public function show(Podcast $podcast): JsonResponse
    {
        return response()->json(['data' => $podcast->apiItem()]);
    }

    /** Episodes of one podcast, newest first. */
    public function episodes(Request $request, Podcast $podcast): JsonResponse
    {
        return $this->page($request, $podcast);
    }

    /** Episodes of every podcast, newest first (`unplayed=1` is the "new episodes" list). */
    public function allEpisodes(Request $request): JsonResponse
    {
        return $this->page($request, null);
    }

    public function episode(PodcastEpisode $episode): JsonResponse
    {
        $position = PlaybackPosition::where('kind', PlaybackPosition::EPISODE)->where('item_id', $episode->id)->value('position');

        return response()->json(['data' => $episode->load('podcast')->apiItem($position)]);
    }

    public function played(Request $request, PodcastEpisode $episode): JsonResponse
    {
        $data = $request->validate(['played' => ['nullable', 'boolean']]);
        $played = (bool) ($data['played'] ?? true);

        Podcasts::markPlayed($episode, $played);

        return response()->json(['status' => 'ok', 'id' => $episode->id, 'played' => $played]);
    }

    public function markAllPlayed(Podcast $podcast): JsonResponse
    {
        return response()->json(['status' => 'ok', 'changed' => Podcasts::markAllPlayed($podcast)]);
    }

    /** Plays an episode on a device (capability `stream_url`); `resume=false` starts it from the beginning. */
    public function play(Request $request, Device $device, PodcastEpisode $episode): JsonResponse
    {
        $request->validate(['resume' => ['nullable', 'boolean']]);

        try {
            return response()->json(EpisodePlayback::play($device, $episode->load('podcast')));
        } catch (DeviceUnreachable $e) {
            return response()->json(['error' => 'unreachable', 'message' => $e->getMessage()], 503);
        } catch (UnsupportedOperationException $e) {
            return response()->json(['error' => 'unsupported', 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'driver_error', 'message' => 'The device did not respond: '.$e->getMessage()], 502);
        }
    }

    private function page(Request $request, ?Podcast $podcast): JsonResponse
    {
        $data = $request->validate([
            'unplayed' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            return response()->json(Podcasts::episodes($podcast, (bool) ($data['unplayed'] ?? false), (int) ($data['limit'] ?? 50), $data['cursor'] ?? null));
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'invalid', 'message' => 'Bad cursor.'], 422);
        }
    }
}
