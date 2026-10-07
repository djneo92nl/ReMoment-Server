<?php

namespace Tests\Support;

use App\Services\SpotifyTokenService;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;

/** Test fixtures for the Spotify connection: a connected account with a mocked Web API client, or none. */
trait FakesSpotify
{
    /** Spotify is connected; the given (or a new mock) Web API client answers. */
    protected function connectSpotify(?SpotifyWebAPI $api = null): SpotifyWebAPI
    {
        $api ??= Mockery::mock(SpotifyWebAPI::class);

        $this->mock(SpotifyTokenService::class, function ($mock) use ($api) {
            $mock->shouldReceive('isConnected')->andReturn(true);
            $mock->shouldReceive('makeApiClient')->andReturn($api);
        });

        return $api;
    }

    protected function disconnectSpotify(): void
    {
        $this->mock(SpotifyTokenService::class, fn ($mock) => $mock->shouldReceive('isConnected')->andReturn(false));
    }
}
