<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Podcasts (docs/api/podcasts.md): RSS subscriptions and their episodes, streamed from the publisher to a device with the stream_url capability. Open actions: list (subscriptions with unplayed counts), episodes (optional podcast_id; unplayed=true is the "new episodes" list; newest first, limit, cursor), play (device_id, episode_id; continues where it stopped, resume=false starts over), mark_played (episode_id, played default true), mark_all_played (podcast_id). ADMIN actions (need the admin token): subscribe (feed_url: fetched at once, 422 invalid_feed when it is not an RSS podcast), refresh (podcast_id: check now, 429 within 5 minutes of the last check), keep (podcast_id, keep_episodes: newest episodes kept, 0 = all, null = server default), unsubscribe (podcast_id; confirm=true; removes its episodes and bookmarks). Where an episode stopped: continue_listening.')]
class Podcasts extends Tool
{
    use CallsApi;

    private const ACTIONS = ['list', 'episodes', 'play', 'mark_played', 'mark_all_played', 'subscribe', 'refresh', 'keep', 'unsubscribe'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(self::ACTIONS)->required(),
            'podcast_id' => $schema->integer()->description('episodes (optional), mark_all_played, refresh, keep, unsubscribe'),
            'episode_id' => $schema->integer()->description('play, mark_played'),
            'device_id' => $schema->integer()->description('play: the device'),
            'resume' => $schema->boolean()->description('play: false starts from the beginning'),
            'played' => $schema->boolean()->description('mark_played: false marks it unplayed again'),
            'unplayed' => $schema->boolean()->description('episodes: only unplayed ones'),
            'limit' => $schema->integer()->description('episodes: 1-100, default 50'),
            'cursor' => $schema->string()->description('episodes: next_cursor of the previous page'),
            'feed_url' => $schema->string()->description('subscribe: the RSS address'),
            'keep_episodes' => $schema->integer()->description('keep: newest episodes kept; 0 = all; omit or null = the server default'),
            'confirm' => $schema->boolean()->description('unsubscribe: must be true'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'action' => 'required|in:'.implode(',', self::ACTIONS),
            'podcast_id' => 'nullable|integer', 'episode_id' => 'nullable|integer', 'device_id' => 'nullable|integer',
            'resume' => 'nullable|boolean', 'played' => 'nullable|boolean', 'unplayed' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:100', 'cursor' => 'nullable|string', 'feed_url' => 'nullable|string',
            'keep_episodes' => 'nullable|integer|min:0', 'confirm' => 'nullable|boolean',
        ]);

        $need = fn (string $field) => Response::error("invalid (422): {$field} is required.");
        $podcast = $data['podcast_id'] ?? null;
        $episode = $data['episode_id'] ?? null;

        return match ($data['action']) {
            'list' => $this->api('GET', 'podcasts'),
            'episodes' => $this->api('GET', $podcast ? "podcasts/{$podcast}/episodes" : 'podcast-episodes', $this->filled([
                'unplayed' => $data['unplayed'] ?? null, 'limit' => $data['limit'] ?? null, 'cursor' => $data['cursor'] ?? null,
            ])),
            'play' => !isset($data['device_id'], $episode) ? $need('device_id and episode_id')
                : $this->api('POST', "devices/{$data['device_id']}/podcast-episodes/{$episode}/play", $this->filled(['resume' => $data['resume'] ?? null])),
            'mark_played' => $episode === null ? $need('episode_id') : $this->api('PUT', "podcast-episodes/{$episode}/played", ['played' => $data['played'] ?? true]),
            'mark_all_played' => $podcast === null ? $need('podcast_id') : $this->api('POST', "podcasts/{$podcast}/mark-played"),
            'subscribe' => empty($data['feed_url']) ? $need('feed_url') : $this->api('POST', 'admin/podcasts', ['feed_url' => $data['feed_url']]),
            'refresh' => $podcast === null ? $need('podcast_id') : $this->api('POST', "admin/podcasts/{$podcast}/refresh"),
            'keep' => $podcast === null ? $need('podcast_id') : $this->api('PUT', "admin/podcasts/{$podcast}", ['keep_episodes' => $data['keep_episodes'] ?? null]),
            'unsubscribe' => $podcast === null ? $need('podcast_id')
                : (empty($data['confirm']) ? Response::error('invalid (422): confirm=true is required to remove a podcast and its episodes.') : $this->api('DELETE', "admin/podcasts/{$podcast}")),
        };
    }
}
