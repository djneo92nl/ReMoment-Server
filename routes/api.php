<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\BeolinkStreamController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DeepAnalysisController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceSettingsController;
use App\Http\Controllers\Api\DeviceSleepController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\ExternalCatalogueController;
use App\Http\Controllers\Api\InfoController;
use App\Http\Controllers\Api\JellyfinController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\LiteArtworkController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\PhysicalController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\RemoteKeysController;
use App\Http\Controllers\Api\SceneController;
use App\Http\Controllers\Api\SourceControlsController;
use App\Http\Controllers\Api\SpotifyStreamController;
use App\Http\Controllers\Api\StorageController;
use App\Http\Controllers\Api\SyncGroupController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TrackStemsController;
use Illuminate\Support\Facades\Route;

Route::get('/info', InfoController::class);
Route::get('/alerts', [AlertController::class, 'index']);
Route::middleware('throttle:20,1')->group(function () {
    Route::post('/push/subscribe', [PushSubscriptionController::class, 'store']);
    Route::delete('/push/subscribe', [PushSubscriptionController::class, 'destroy']);
});

// Tiny GIF/BMP/PGM covers for old clients: /api/artwork/{hash}/64.gif?colors=16, the hash is the artwork `hash` of a track or `lite` of a play command.
Route::get('/artwork/{hash}/{size}.{format}', [LiteArtworkController::class, 'show'])
    ->where(['hash' => '[0-9a-f]{32}', 'size' => '[0-9]+', 'format' => 'gif|bmp|pgm']);

Route::get('/players', [PlayerController::class, 'index']);
Route::post('/players/register', [PlayerController::class, 'register']);
Route::put('/players/{token}/state', [PlayerController::class, 'state']);
Route::get('/players/{token}/commands', [PlayerController::class, 'commands']);

Route::get('/devices', [DeviceController::class, 'index']);
Route::get('/devices/{device}', [DeviceController::class, 'show']);

Route::post('/devices/{device}/{action}', [DeviceController::class, 'action'])
    ->whereIn('action', ['play', 'pause', 'stop', 'next', 'previous']);

Route::get('/devices/{device}/volume', [DeviceController::class, 'getVolume']);
Route::put('/devices/{device}/volume', [DeviceController::class, 'setVolume']);
Route::get('/devices/{device}/volume-limits', [DeviceController::class, 'getVolumeLimits']);
Route::get('/devices/{device}/mute', [DeviceController::class, 'getMute']);
Route::put('/devices/{device}/mute', [DeviceController::class, 'setMute']);

Route::put('/devices/{device}/seek', [DeviceController::class, 'seek']);
Route::get('/devices/{device}/queue', [DeviceController::class, 'queue']);
Route::post('/devices/{device}/queue/jump', [DeviceController::class, 'queueJump']);
Route::post('/devices/{device}/queue', [QueueController::class, 'store']);
Route::delete('/devices/{device}/queue', [QueueController::class, 'clear']);
Route::delete('/devices/{device}/queue/{position}', [QueueController::class, 'destroy'])->whereNumber('position');
Route::put('/devices/{device}/queue/{position}', [QueueController::class, 'move'])->whereNumber('position');

Route::put('/devices/{device}/shuffle', [DeviceController::class, 'setShuffle']);
Route::put('/devices/{device}/repeat', [DeviceController::class, 'setRepeat']);
Route::put('/devices/{device}/like', [DeviceController::class, 'setLike']);
Route::put('/devices/{device}/power', [DeviceController::class, 'setPower']);

Route::get('/devices/{device}/sound-adjustment', [DeviceSettingsController::class, 'getSoundAdjustment']);
Route::put('/devices/{device}/sound-adjustment', [DeviceSettingsController::class, 'setSoundAdjustment']);
Route::get('/devices/{device}/bluetooth', [DeviceSettingsController::class, 'getBluetooth']);
Route::put('/devices/{device}/bluetooth', [DeviceSettingsController::class, 'setBluetooth']);
Route::delete('/devices/{device}/bluetooth/devices/{deviceId}', [DeviceSettingsController::class, 'removeBluetoothDevice'])->where('deviceId', '.+');
// Network settings (read and write) need the admin token: a wrong value can lock a device out. They are in the admin.token group below.
Route::get('/devices/{device}/wired-speakers', [DeviceSettingsController::class, 'getWiredSpeakers']);
Route::put('/devices/{device}/wired-speakers/{speakerId}', [DeviceSettingsController::class, 'setWiredSpeaker'])->where('speakerId', '[A-Za-z0-9_]+');
Route::get('/devices/{device}/wireless-speakers', [DeviceSettingsController::class, 'getWirelessSpeakers']);
Route::post('/devices/{device}/wireless-speakers/scan', [DeviceSettingsController::class, 'scanWirelessSpeakers']);
Route::get('/devices/{device}/info', [DeviceSettingsController::class, 'getInfo']);
Route::put('/devices/{device}/info', [DeviceSettingsController::class, 'setInfo']);
// Scene-level sleep timer (fade, stop or standby, restore the volume); distinct from the device's own sleep-timer below
Route::get('/devices/{device}/sleep', [DeviceSleepController::class, 'show']);
Route::post('/devices/{device}/sleep', [DeviceSleepController::class, 'start']);
Route::delete('/devices/{device}/sleep', [DeviceSleepController::class, 'cancel']);
Route::get('/devices/{device}/sleep-timer', [DeviceSettingsController::class, 'getSleepTimer']);
Route::put('/devices/{device}/sleep-timer', [DeviceSettingsController::class, 'setSleepTimer']);
Route::post('/devices/{device}/digits', [DeviceSettingsController::class, 'sendDigits']);
Route::get('/devices/{device}/keys', [RemoteKeysController::class, 'index']);
Route::post('/devices/{device}/keys', [RemoteKeysController::class, 'press']);
Route::get('/devices/{device}/jellyfin', [JellyfinController::class, 'index']);
Route::post('/devices/{device}/jellyfin/play', [JellyfinController::class, 'play']);
// Media servers (workstream 15, docs/api/media-servers.md): the generic shelves and play-on-device; the two Jellyfin routes above wrap them.
Route::get('/media-servers', [MediaController::class, 'servers']);
Route::get('/devices/{device}/media', [MediaController::class, 'show']);
Route::post('/devices/{device}/media/play', [MediaController::class, 'play']);

Route::get('/devices/{device}/sources', [DeviceController::class, 'sources']);
Route::post('/devices/{device}/sources/activate', [DeviceController::class, 'activateSource']);
// Show/hide and reorder a source: a display preference, open like the web page (decided 2026-11-22); the admin path below is kept as an alias
Route::put('/devices/{device}/sources/{sourceId}', [DeviceController::class, 'updateSource'])->where('sourceId', '[^/]+');

Route::get('/devices/{device}/controls', [SourceControlsController::class, 'index']);
Route::post('/devices/{device}/controls/{control}', [SourceControlsController::class, 'run']);

Route::get('/spotify-stream', [SpotifyStreamController::class, 'show']);
Route::post('/devices/{device}/spotify-stream', [SpotifyStreamController::class, 'play']);
Route::get('/line-ins', [\App\Http\Controllers\Api\LineInController::class, 'index']);
Route::post('/devices/{device}/line-in', [\App\Http\Controllers\Api\LineInController::class, 'play']);
Route::post('/librespot/event', [SpotifyStreamController::class, 'event']);

Route::get('/beolink-stream', [BeolinkStreamController::class, 'show']);
Route::post('/devices/{device}/beolink-stream', [BeolinkStreamController::class, 'play']);
Route::delete('/devices/{device}/beolink-stream', [BeolinkStreamController::class, 'stop']);

Route::get('/devices/{device}/radio', [DeviceController::class, 'radioStations']);
Route::get('/radio/{station}/now', [DeviceController::class, 'radioNow']);
Route::put('/radio/{station}/favorite', [DeviceController::class, 'favoriteRadio']);
Route::post('/devices/{device}/radio/{station}', [DeviceController::class, 'playRadio']);
Route::get('/radio/relays', [\App\Http\Controllers\Api\RadioRelayController::class, 'index']);

Route::get('/devices/{device}/multiroom', [DeviceController::class, 'multiroom']);
Route::post('/devices/{device}/multiroom/join', [DeviceController::class, 'multiroomJoin']);
Route::delete('/devices/{device}/multiroom/leave', [DeviceController::class, 'multiroomLeave']);
// Multiroom presets: named groupings, the first device the host; open like multiroom itself (docs/api/multiroom-presets.md)
Route::get('/multiroom/presets', [\App\Http\Controllers\Api\MultiroomPresetController::class, 'index']);
Route::post('/multiroom/presets', [\App\Http\Controllers\Api\MultiroomPresetController::class, 'store']);
Route::put('/multiroom/presets/{preset}', [\App\Http\Controllers\Api\MultiroomPresetController::class, 'update']);
Route::delete('/multiroom/presets/{preset}', [\App\Http\Controllers\Api\MultiroomPresetController::class, 'destroy']);
Route::post('/multiroom/presets/{preset}/activate', [\App\Http\Controllers\Api\MultiroomPresetController::class, 'activate']);

Route::post('/devices/{device}/move', [DeviceController::class, 'move']);

Route::get('/sync-groups', [SyncGroupController::class, 'index']);
Route::post('/sync-groups', [SyncGroupController::class, 'store']);
Route::delete('/sync-groups/{group}', [SyncGroupController::class, 'destroy']);
Route::post('/sync-groups/{group}/members', [SyncGroupController::class, 'addMember']);
Route::put('/sync-groups/{group}/members/{device}', [SyncGroupController::class, 'setOffset']);
Route::delete('/sync-groups/{group}/members/{device}', [SyncGroupController::class, 'removeMember']);

Route::post('/devices/{device}/library/play', [DeviceController::class, 'libraryPlay']);
Route::post('/devices/{device}/library/play-playlist', [DeviceController::class, 'libraryPlayPlaylist']);
Route::post('/devices/{device}/library/play-tracks', [DeviceController::class, 'libraryPlayTracks']);
Route::post('/devices/{device}/library/play-listening-mode', [DeviceController::class, 'libraryPlayListeningMode']);
Route::post('/devices/{device}/library/play-similar', [DeviceController::class, 'libraryPlaySimilar']);
Route::post('/devices/{device}/library/play-daily-mix', [DeviceController::class, 'libraryPlayDailyMix']);
Route::post('/devices/{device}/library/play-journey', [DeviceController::class, 'libraryPlayJourney']);
Route::post('/devices/{device}/library/play-artist-tour', [DeviceController::class, 'libraryPlayArtistTour']);
Route::get('/library/journey', [\App\Http\Controllers\Api\LibraryTourController::class, 'journey']);
Route::get('/library/artists/{artist}/tour', [\App\Http\Controllers\Api\LibraryTourController::class, 'artistTour']);
Route::post('/devices/{device}/library/play-album', [DeviceController::class, 'libraryPlayAlbum']);
Route::post('/devices/{device}/library/play-artist', [DeviceController::class, 'libraryPlayArtist']);
Route::post('/devices/{device}/library/play-external', [DeviceController::class, 'libraryPlayExternal']);

Route::get('/library/libraries', [LibraryController::class, 'libraries']);
Route::get('/library/recent-items', [LibraryController::class, 'recentItems']);
Route::get('/library/artists', [LibraryController::class, 'artists']);
Route::get('/library/artists/{artist}', [LibraryController::class, 'artist']);
Route::get('/library/artists/{artist}/discography', [LibraryController::class, 'discography']);
Route::get('/library/external/{provider}/search', [ExternalCatalogueController::class, 'search']);
Route::get('/library/external/{provider}/artists/{id}', [ExternalCatalogueController::class, 'artist']);
Route::get('/library/external/{provider}/albums/{id}', [ExternalCatalogueController::class, 'album']);
Route::get('/library/external/{provider}/playlists', [ExternalCatalogueController::class, 'playlists']);
Route::get('/library/external/{provider}/{section}', [ExternalCatalogueController::class, 'account'])->whereIn('section', ['saved-albums', 'liked-tracks', 'recent']);
Route::get('/library/external/{provider}/playlists/{id}', [ExternalCatalogueController::class, 'playlist']);
Route::get('/library/physical', [PhysicalController::class, 'index']);
Route::get('/library/physical/lookup', [PhysicalController::class, 'lookup']);
Route::post('/library/physical', [PhysicalController::class, 'store']);
Route::post('/library/physical/import', [PhysicalController::class, 'importPreview']);
Route::post('/library/physical/import/{token}/confirm', [PhysicalController::class, 'importConfirm']);
Route::get('/library/physical/{physical}', [PhysicalController::class, 'show']);
Route::put('/library/physical/{physical}', [PhysicalController::class, 'update']);
Route::delete('/library/physical/{physical}', [PhysicalController::class, 'destroy']);
Route::get('/library/wishlist', [LibraryController::class, 'wishlist']);
Route::get('/library/new-releases', [LibraryController::class, 'newReleases']);
Route::post('/library/wanted-tracks', [LibraryController::class, 'wantTrack']);
Route::delete('/library/wanted-tracks/{wantedTrack}', [LibraryController::class, 'unwantTrack']);
Route::get('/library/catalogue/{catalogue_album}', [LibraryController::class, 'catalogueAlbum']);
Route::put('/library/catalogue/{catalogue_album}/wanted', [LibraryController::class, 'wantCatalogueAlbum']);
Route::put('/library/catalogue/{catalogue_album}/ignored', [LibraryController::class, 'ignoreCatalogueAlbum']);
Route::put('/library/artists/{artist}/favorite', [LibraryController::class, 'favoriteArtist']);
Route::get('/library/tracks', [LibraryController::class, 'tracks']);
Route::get('/library/albums', [LibraryController::class, 'albums']);
Route::get('/library/random-album', [LibraryController::class, 'randomAlbum']);
Route::get('/library/albums/{album}', [LibraryController::class, 'album']);
Route::get('/library/albums/{album}/spotify', [LibraryController::class, 'albumSpotify']);
Route::put('/library/albums/{album}/favorite', [LibraryController::class, 'favoriteAlbum']);
Route::put('/library/tracks/{track}/favorite', [LibraryController::class, 'favoriteTrack']);
Route::get('/library/tracks/{track}/mixes-with', [LibraryController::class, 'mixesWith']);
Route::get('/library/tracks/{track}/related', [\App\Http\Controllers\Api\TrackRelatedController::class, 'show']);
// Upcoming concerts of library artists from MusicBrainz events, opt-in (docs/api/concerts.md)
Route::get('/library/concerts', [\App\Http\Controllers\Api\ConcertController::class, 'index']);
Route::get('/library/artists/{artist}/concerts', [\App\Http\Controllers\Api\ConcertController::class, 'artist']);
Route::get('/library/tracks/{track}/similar', [LibraryController::class, 'similar']);
Route::get('/library/tracks/{track}/similar-sections', [\App\Http\Controllers\Api\SectionSimilarityController::class, 'show']);
Route::get('/library/tracks/{track}/waveform', [LibraryController::class, 'waveform']);
Route::get('/library/listening-modes', [LibraryController::class, 'listeningModes']);
Route::get('/library/discover', [\App\Http\Controllers\Api\DiscoverController::class, 'index']);
Route::get('/library/history', [\App\Http\Controllers\Api\PlayHistoryController::class, 'index']);
// Resume positions of long tracks and podcast episodes (docs/api/playback-positions.md)
Route::get('/library/positions', [\App\Http\Controllers\Api\PlaybackPositionController::class, 'index']);
Route::put('/library/positions/{kind}/{id}', [\App\Http\Controllers\Api\PlaybackPositionController::class, 'update'])->whereIn('kind', \App\Models\PlaybackPosition::KINDS)->whereNumber('id');
Route::delete('/library/positions/{kind}/{id}', [\App\Http\Controllers\Api\PlaybackPositionController::class, 'destroy'])->whereIn('kind', \App\Models\PlaybackPosition::KINDS)->whereNumber('id');
// Podcasts (docs/api/podcasts.md): reading, playing and marking played are open; subscriptions are in the admin group below
Route::get('/podcasts', [\App\Http\Controllers\Api\PodcastController::class, 'index']);
Route::get('/podcasts/{podcast}', [\App\Http\Controllers\Api\PodcastController::class, 'show'])->whereNumber('podcast');
Route::get('/podcasts/{podcast}/episodes', [\App\Http\Controllers\Api\PodcastController::class, 'episodes'])->whereNumber('podcast');
Route::post('/podcasts/{podcast}/mark-played', [\App\Http\Controllers\Api\PodcastController::class, 'markAllPlayed'])->whereNumber('podcast');
Route::get('/podcast-episodes', [\App\Http\Controllers\Api\PodcastController::class, 'allEpisodes']);
Route::get('/podcast-episodes/{episode}', [\App\Http\Controllers\Api\PodcastController::class, 'episode'])->whereNumber('episode');
Route::put('/podcast-episodes/{episode}/played', [\App\Http\Controllers\Api\PodcastController::class, 'played'])->whereNumber('episode');
Route::post('/devices/{device}/podcast-episodes/{episode}/play', [\App\Http\Controllers\Api\PodcastController::class, 'play'])->whereNumber('episode');
Route::get('/devices/{device}/history', [\App\Http\Controllers\Api\PlayHistoryController::class, 'device']);
Route::get('/library/albums/{album}/plays', [\App\Http\Controllers\Api\PlayHistoryController::class, 'album']);
Route::get('/library/artists/{artist}/plays', [\App\Http\Controllers\Api\PlayHistoryController::class, 'artist']);
Route::get('/library/stats', [\App\Http\Controllers\Api\ListeningStatsController::class, 'index']);
Route::get('/library/overview', [LibraryController::class, 'overview']);
Route::get('/library/insights', [\App\Http\Controllers\Api\ListeningInsightsController::class, 'index']);
Route::get('/library/health', [LibraryController::class, 'health']);
Route::get('/library/health/{report}', [LibraryController::class, 'healthReport']);
Route::get('/library/genres', [LibraryController::class, 'genres']);
Route::get('/library/genres/{genre:slug}', [LibraryController::class, 'genre']);
Route::get('/library/playlists', [LibraryController::class, 'playlists']);
Route::get('/library/editable-playlists', [LibraryController::class, 'editablePlaylists']);
Route::post('/library/playlists', [LibraryController::class, 'createPlaylist']);
Route::post('/library/playlists/{playlist}/tracks', [LibraryController::class, 'addPlaylistTracks']);
Route::get('/library/playlists/{playlist}', [LibraryController::class, 'playlist']);
Route::get('/library/recent', [LibraryController::class, 'recent']);
Route::get('/library/favorites', [LibraryController::class, 'favorites']);
Route::get('/library/search', [LibraryController::class, 'search']);

Route::post('/clients/register', [ClientController::class, 'register']);
Route::get('/clients/status/{registrationToken}', [ClientController::class, 'status']);
Route::get('/clients/{apiToken}/devices', [ClientController::class, 'devices']);
Route::put('/clients/{apiToken}/heartbeat', [ClientController::class, 'heartbeat']);
Route::get('/clients/{apiToken}/artwork', [ClientController::class, 'artwork']);

// Scenes: household-wide step lists, open like playlists and multiroom presets (docs/api/scenes.md)
Route::get('/scenes', [SceneController::class, 'index']);
Route::post('/scenes', [SceneController::class, 'store']);
Route::get('/scenes/{scene}', [SceneController::class, 'show']);
Route::put('/scenes/{scene}', [SceneController::class, 'update']);
Route::delete('/scenes/{scene}', [SceneController::class, 'destroy']);
Route::post('/scenes/{scene}/activate', [SceneController::class, 'activate']);

// Tap to play: NFC tags and QR codes (docs/api/tags.md); managing them is in the admin group
Route::get('/tags/{uid}', [TagController::class, 'show']);
Route::post('/tags/{uid}/play', [TagController::class, 'play']);

// Announcements: a clip on speakers, then back to what played (docs/api/announcements.md); clips are managed in the admin group
Route::get('/announcements/clips', [AnnouncementController::class, 'clips']);
Route::post('/announcements', [AnnouncementController::class, 'store']);

// Node routes: what a ReMoment Node calls itself, with its own client token as Bearer (docs/architecture/node-agent.md)
Route::prefix('nodes')->group(function () {
    Route::get('/job', [\App\Http\Controllers\Api\NodeController::class, 'next']);
    Route::post('/jobs/{job}/accept', [\App\Http\Controllers\Api\NodeController::class, 'accept']);
    Route::post('/jobs/{job}/progress', [\App\Http\Controllers\Api\NodeController::class, 'progress']);
    Route::post('/jobs/{job}/result', [\App\Http\Controllers\Api\NodeController::class, 'result']);
    // Ripper role (docs/api/rips.md): which nodes can rip (open) and the node's upload of a ripped file (Bearer, only during a rip)
    Route::get('/rippers', [\App\Http\Controllers\Api\RipController::class, 'rippers']);
    Route::post('/files', [\App\Http\Controllers\Api\NodeFileController::class, 'store']);
    // Sync target role (docs/api/sync.md): the node asks for its plan and reports, for the target bound to it only
    Route::post('/sync/plan', [\App\Http\Controllers\Api\NodeSyncController::class, 'plan']);
    Route::post('/sync/report', [\App\Http\Controllers\Api\NodeSyncController::class, 'report']);
});

// Admin token login for apps. Routes that change configuration go in the `admin.token` group (see docs/api/admin-auth.md).
Route::post('/admin/login', [AdminAuthController::class, 'login']);
// Signed, temporary download of one export part: the signature is the authorization (docs/api/export.md)
Route::get('/library/exports/{id}/download/{part}', [ExportController::class, 'download'])
    ->middleware('signed:relative')->whereNumber('part')->name('api.library.exports.download');
// Deep analysis of one track on request (sections, stems, stem_audio) and the kept stem audio: open like the rest of the
// library, no admin token (docs/api/deep-analysis.md, docs/api/stems.md). The download is signed.
Route::post('/library/tracks/{track}/deep-analysis', [DeepAnalysisController::class, 'store']);
Route::get('/library/tracks/{track}/deep-analysis', [DeepAnalysisController::class, 'show']);
Route::delete('/library/tracks/{track}/deep-analysis/{kind}', [DeepAnalysisController::class, 'destroy']);
// Opt-in download of the Demucs weights (licence unclear): the state is open, starting it is admin only (below).
Route::get('/stems/weights', [\App\Http\Controllers\Api\StemWeightsController::class, 'show']);
Route::get('/library/tracks/{track}/stems', [TrackStemsController::class, 'show']);
Route::get('/library/tracks/{track}/stems/{stem}', [TrackStemsController::class, 'download'])
    ->middleware('signed:relative')->name('api.library.stems.download');
// Signed, 6-hour download of one file of a sync (docs/api/sync.md): the signature is the authorization, Range works
Route::get('/sync/files/{target}/{key}/{fingerprint}', [\App\Http\Controllers\Api\SyncFileController::class, 'show'])
    ->middleware('signed:relative')->where('key', '[^/]+')->name('api.sync.files');
Route::middleware('admin.token')->group(function () {
    Route::get('/admin/me', [AdminAuthController::class, 'me']);

    // Podcast subscriptions: add by RSS address, keep, refresh, remove (docs/api/podcasts.md)
    Route::post('/admin/podcasts', [\App\Http\Controllers\Api\AdminPodcastController::class, 'store']);
    Route::put('/admin/podcasts/{podcast}', [\App\Http\Controllers\Api\AdminPodcastController::class, 'update'])->whereNumber('podcast');
    Route::delete('/admin/podcasts/{podcast}', [\App\Http\Controllers\Api\AdminPodcastController::class, 'destroy'])->whereNumber('podcast');
    Route::post('/admin/podcasts/{podcast}/refresh', [\App\Http\Controllers\Api\AdminPodcastController::class, 'refresh'])->whereNumber('podcast');

    // App sign-ins: list and revoke (docs/api/admin-tokens.md)
    Route::get('/admin/tokens', [\App\Http\Controllers\Api\AdminTokenController::class, 'index']);
    Route::delete('/admin/tokens', [\App\Http\Controllers\Api\AdminTokenController::class, 'destroyAll']);
    Route::delete('/admin/tokens/{token}', [\App\Http\Controllers\Api\AdminTokenController::class, 'destroy']);

    // Device network settings: admin token only (decided 2026-10-26; docs/api/device-settings.md). Renaming stays open.
    Route::get('/devices/{device}/network', [DeviceSettingsController::class, 'getNetwork']);
    Route::put('/devices/{device}/network/interface', [DeviceSettingsController::class, 'setNetworkInterface']);
    Route::put('/devices/{device}/network/wired', [DeviceSettingsController::class, 'setWiredNetwork']);
    Route::put('/devices/{device}/network/wifi', [DeviceSettingsController::class, 'joinWifi']);

    // Audio analysis status and actions: the data of /settings/analysis (docs/api/admin-analysis.md)
    Route::get('/admin/analysis', [\App\Http\Controllers\Api\AdminAnalysisController::class, 'show']);
    Route::get('/admin/analysis/problems', [\App\Http\Controllers\Api\AdminAnalysisController::class, 'problems']);
    Route::post('/admin/analysis/run', [\App\Http\Controllers\Api\AdminAnalysisController::class, 'run']);
    Route::post('/admin/analysis/files/{file}/analyse', [\App\Http\Controllers\Api\AdminAnalysisController::class, 'analyse']);
    Route::post('/admin/analysis/files/{file}/retry', [\App\Http\Controllers\Api\AdminAnalysisController::class, 'retry']);

    // Library settings and maintenance (docs/api/admin-library.md)
    Route::get('/admin/library/settings', [\App\Http\Controllers\Api\AdminLibraryController::class, 'settings']);
    Route::put('/admin/library/settings', [\App\Http\Controllers\Api\AdminLibraryController::class, 'updateSettings']);
    Route::post('/admin/library/maintenance/{action}', [\App\Http\Controllers\Api\AdminLibraryController::class, 'maintenance']);

    // Sync to offline targets: a USB stick on a node, the iOS app's offline library (docs/api/sync.md)
    Route::get('/admin/sync/targets', [\App\Http\Controllers\Api\SyncTargetController::class, 'index']);
    Route::post('/admin/sync/targets', [\App\Http\Controllers\Api\SyncTargetController::class, 'store']);
    Route::get('/admin/sync/targets/{target}', [\App\Http\Controllers\Api\SyncTargetController::class, 'show']);
    Route::put('/admin/sync/targets/{target}', [\App\Http\Controllers\Api\SyncTargetController::class, 'update']);
    Route::delete('/admin/sync/targets/{target}', [\App\Http\Controllers\Api\SyncTargetController::class, 'destroy']);
    Route::post('/admin/sync/targets/{target}/estimate', [\App\Http\Controllers\Api\SyncTargetController::class, 'estimate']);
    Route::post('/admin/sync/targets/{target}/plan', [\App\Http\Controllers\Api\SyncTargetController::class, 'plan']);
    Route::post('/admin/sync/targets/{target}/report', [\App\Http\Controllers\Api\SyncTargetController::class, 'report']);
    Route::post('/admin/sync/targets/{target}/plays', [\App\Http\Controllers\Api\SyncTargetController::class, 'plays']);
    Route::post('/admin/sync/targets/{target}/run', [\App\Http\Controllers\Api\SyncTargetController::class, 'run']);
    Route::post('/admin/sync/targets/{target}/volume', [\App\Http\Controllers\Api\SyncTargetController::class, 'volume']);
    Route::delete('/admin/sync/targets/{target}/run', [\App\Http\Controllers\Api\SyncTargetController::class, 'cancel']);
    Route::get('/admin/sync/files/{target}/{key}/{fingerprint}', [\App\Http\Controllers\Api\SyncFileController::class, 'show'])->where('key', '[^/]+');

    // Health for apps: the data of /settings/health and the external-service probes (docs/api/admin-health.md)
    Route::get('/admin/health', [\App\Http\Controllers\Api\AdminHealthController::class, 'show']);
    Route::get('/admin/health/services', [\App\Http\Controllers\Api\AdminHealthController::class, 'services']);
    Route::post('/admin/health/services/check', [\App\Http\Controllers\Api\AdminHealthController::class, 'check']);

    // The optional second copy of every backup: a directory path, a test-write, the last copy (docs/api/admin-backup.md)
    Route::get('/admin/backup/copy-path', [\App\Http\Controllers\Api\AdminBackupController::class, 'show']);
    Route::put('/admin/backup/copy-path', [\App\Http\Controllers\Api\AdminBackupController::class, 'update']);
    Route::post('/admin/backup/copy-path/test', [\App\Http\Controllers\Api\AdminBackupController::class, 'test']);

    // The Home Assistant rest_command package as a YAML download, the file `ha:package` writes (docs/api/admin-ha.md)
    Route::get('/admin/ha/package', [\App\Http\Controllers\Api\AdminHaController::class, 'package']);

    // Client registrations: list, approve, reject, edit, new token, remove (docs/api/admin-clients.md)
    Route::get('/admin/clients', [\App\Http\Controllers\Api\AdminClientController::class, 'index']);
    Route::get('/admin/clients/{client}', [\App\Http\Controllers\Api\AdminClientController::class, 'show']);
    Route::put('/admin/clients/{client}', [\App\Http\Controllers\Api\AdminClientController::class, 'update']);
    Route::delete('/admin/clients/{client}', [\App\Http\Controllers\Api\AdminClientController::class, 'destroy']);
    Route::post('/admin/clients/{client}/approve', [\App\Http\Controllers\Api\AdminClientController::class, 'approve']);
    Route::post('/admin/clients/{client}/reject', [\App\Http\Controllers\Api\AdminClientController::class, 'reject']);
    Route::post('/admin/clients/{client}/regenerate-token', [\App\Http\Controllers\Api\AdminClientController::class, 'regenerateToken']);

    // Radio stations: add, change, remove and the stream finder (docs/api/admin-radio.md)
    Route::get('/admin/radio-stations', [\App\Http\Controllers\Api\AdminRadioStationController::class, 'index']);
    Route::post('/admin/radio-stations', [\App\Http\Controllers\Api\AdminRadioStationController::class, 'store']);
    Route::get('/admin/radio-stations/search', [\App\Http\Controllers\Api\AdminRadioStationController::class, 'search']);
    Route::post('/admin/radio-stations/directory', [\App\Http\Controllers\Api\AdminRadioStationController::class, 'addFromDirectory']);
    Route::get('/admin/radio-stations/{station}', [\App\Http\Controllers\Api\AdminRadioStationController::class, 'show']);
    Route::put('/admin/radio-stations/{station}', [\App\Http\Controllers\Api\AdminRadioStationController::class, 'update']);
    Route::delete('/admin/radio-stations/{station}', [\App\Http\Controllers\Api\AdminRadioStationController::class, 'destroy']);

    // Devices: add, change, hide and remove by hand, and the discovery inbox (docs/api/admin-devices.md)
    Route::get('/admin/device-models', [\App\Http\Controllers\Api\AdminDeviceController::class, 'models']);
    Route::get('/admin/devices', [\App\Http\Controllers\Api\AdminDeviceController::class, 'index']);
    Route::post('/admin/devices', [\App\Http\Controllers\Api\AdminDeviceController::class, 'store']);
    Route::get('/admin/devices/{device}', [\App\Http\Controllers\Api\AdminDeviceController::class, 'show']);
    Route::put('/admin/devices/{device}', [\App\Http\Controllers\Api\AdminDeviceController::class, 'update']);
    Route::delete('/admin/devices/{device}', [\App\Http\Controllers\Api\AdminDeviceController::class, 'destroy']);
    Route::get('/admin/discovery', [\App\Http\Controllers\Api\AdminDiscoveryController::class, 'index']);
    Route::post('/admin/discovery/scan', [\App\Http\Controllers\Api\AdminDiscoveryController::class, 'scan']);
    Route::post('/admin/discovery/add-all', [\App\Http\Controllers\Api\AdminDiscoveryController::class, 'addAll']);
    Route::post('/admin/discovery/{record}/add', [\App\Http\Controllers\Api\AdminDiscoveryController::class, 'add']);
    Route::post('/admin/discovery/{record}/ignore', [\App\Http\Controllers\Api\AdminDiscoveryController::class, 'ignore']);
    Route::post('/admin/discovery/{record}/restore', [\App\Http\Controllers\Api\AdminDiscoveryController::class, 'restore']);

    // Per-queue health, pause/resume, cancel and run-first of a queue's jobs (docs/api/admin-queues.md)
    Route::get('/admin/queues', [\App\Http\Controllers\Api\AdminQueueController::class, 'index']);
    Route::post('/admin/queues/{queue}/pause', [\App\Http\Controllers\Api\AdminQueueController::class, 'pause']);
    Route::post('/admin/queues/{queue}/resume', [\App\Http\Controllers\Api\AdminQueueController::class, 'resume']);
    Route::post('/admin/queues/{queue}/cancel', [\App\Http\Controllers\Api\AdminQueueController::class, 'cancel']);
    Route::post('/admin/queues/{queue}/prioritise', [\App\Http\Controllers\Api\AdminQueueController::class, 'prioritise']);

    // Jobs for a ReMoment Node (docs/architecture/node-agent.md)
    Route::get('/admin/nodes/{client}/jobs', [\App\Http\Controllers\Api\AdminNodeJobController::class, 'index']);
    Route::post('/admin/nodes/{client}/jobs', [\App\Http\Controllers\Api\AdminNodeJobController::class, 'store']);
    Route::post('/admin/nodes/{client}/jobs/{job}/cancel', [\App\Http\Controllers\Api\AdminNodeJobController::class, 'cancel']);
    // Ripping a CD on a node: check the disc, choose the release, progress (docs/api/rips.md)
    Route::get('/admin/rips', [\App\Http\Controllers\Api\RipController::class, 'index']);
    Route::post('/admin/rips', [\App\Http\Controllers\Api\RipController::class, 'store']);
    Route::get('/admin/rips/{rip}', [\App\Http\Controllers\Api\RipController::class, 'show']);
    Route::post('/admin/rips/{rip}/cancel', [\App\Http\Controllers\Api\RipController::class, 'cancel']);
    Route::delete('/admin/token', [AdminAuthController::class, 'logout']);
    Route::post('/admin/alerts/{alert}/ack', [AlertController::class, 'acknowledge']);

    // Redacted diagnostics zip to attach to an issue (docs/architecture/diagnostics.md)
    Route::get('/admin/diagnostics', [\App\Http\Controllers\DiagnosticsController::class, 'download']);

    // Starts the opt-in download of the stems service's Demucs weights (docs/api/stems.md, "Weights")
    Route::post('/admin/stems/weights', [\App\Http\Controllers\Api\StemWeightsController::class, 'store']);

    // Export from the library as zip parts (docs/api/export.md); admin only, the download itself is signed
    Route::post('/library/exports/estimate', [ExportController::class, 'estimate']);
    Route::post('/library/exports', [ExportController::class, 'store']);
    Route::get('/library/exports', [ExportController::class, 'index']);
    Route::get('/library/exports/{id}', [ExportController::class, 'show']);
    Route::delete('/library/exports/{id}', [ExportController::class, 'destroy']);

    // Storage locations and the library's files (docs/architecture/file-storage.md)
    Route::get('/admin/storage/locations', [StorageController::class, 'locations']);
    Route::post('/admin/storage/locations', [StorageController::class, 'storeLocation']);
    Route::put('/admin/storage/locations/{location}', [StorageController::class, 'updateLocation']);
    Route::delete('/admin/storage/locations/{location}', [StorageController::class, 'destroyLocation']);
    Route::post('/admin/storage/locations/{location}/test', [StorageController::class, 'testLocation']);
    Route::post('/admin/storage/locations/{location}/scan', [StorageController::class, 'scan']);
    Route::post('/admin/storage/import', [StorageController::class, 'import']);
    Route::get('/admin/storage/playback-loudness', [StorageController::class, 'playbackLoudness']);
    Route::put('/admin/storage/playback-loudness', [StorageController::class, 'setPlaybackLoudness']);
    Route::post('/admin/storage/reorganize', [StorageController::class, 'reorganize']);
    Route::post('/admin/files', [StorageController::class, 'upload']);
    Route::get('/admin/tracks/{track}/files', [StorageController::class, 'trackFiles']);
    Route::delete('/admin/tracks/{track}/files', [StorageController::class, 'destroyTrack']);
    Route::delete('/admin/files/{file}', [StorageController::class, 'destroyFile']);
    // Tag and cover suggestions: accept (writes the tag into the file / fetches the cover) or dismiss (docs/api/suggestions.md)
    Route::post('/admin/suggestions/{id}/accept', [\App\Http\Controllers\Api\SuggestionController::class, 'accept']);
    Route::post('/admin/suggestions/{id}/dismiss', [\App\Http\Controllers\Api\SuggestionController::class, 'dismiss']);
    // Device source visibility (the web UI's DeviceSourceManager)
    Route::post('/admin/announcements/clips', [AnnouncementController::class, 'upload']);
    Route::delete('/admin/announcements/clips/{name}', [AnnouncementController::class, 'destroy']);
    Route::get('/admin/tags', [TagController::class, 'index']);
    Route::get('/admin/tags/last-scanned', [TagController::class, 'lastScanned']);
    Route::post('/admin/tags', [TagController::class, 'store']);
    Route::put('/admin/tags/{tag}', [TagController::class, 'update']);
    Route::delete('/admin/tags/{tag}', [TagController::class, 'destroy']);
    Route::put('/admin/devices/{device}/volume-limits', [DeviceController::class, 'setVolumeLimits']);
    Route::put('/admin/devices/{device}/sources/{sourceId}', [DeviceController::class, 'updateSource'])->where('sourceId', '[^/]+');
    // Sub devices: links between a host and a device connected to it (docs/api/sub-devices.md)
    Route::get('/admin/devices/{device}/links', [\App\Http\Controllers\Api\DeviceLinkController::class, 'index']);
    Route::get('/admin/devices/{device}/link-suggestions', [\App\Http\Controllers\Api\DeviceLinkController::class, 'suggestions']);
    Route::post('/admin/device-links', [\App\Http\Controllers\Api\DeviceLinkController::class, 'store']);
    Route::put('/admin/device-links/{link}', [\App\Http\Controllers\Api\DeviceLinkController::class, 'update']);
    Route::delete('/admin/device-links/{link}', [\App\Http\Controllers\Api\DeviceLinkController::class, 'destroy']);
    Route::post('/admin/files/{file}/move', [StorageController::class, 'move']);
    // Tag editor for own files and albums (docs/api/tag-editor.md): tags, cover, batch per album, undo of the last edit
    Route::get('/admin/files/{file}/tags', [\App\Http\Controllers\Api\TagEditorController::class, 'show']);
    Route::put('/admin/files/{file}/tags', [\App\Http\Controllers\Api\TagEditorController::class, 'update']);
    Route::post('/admin/files/{file}/tags/undo', [\App\Http\Controllers\Api\TagEditorController::class, 'undo']);
    Route::post('/admin/files/{file}/cover', [\App\Http\Controllers\Api\TagEditorController::class, 'setCover']);
    Route::delete('/admin/files/{file}/cover', [\App\Http\Controllers\Api\TagEditorController::class, 'removeCover']);
    Route::get('/admin/albums/{album}/tags', [\App\Http\Controllers\Api\TagEditorController::class, 'album']);
    Route::put('/admin/albums/{album}/tags', [\App\Http\Controllers\Api\TagEditorController::class, 'updateAlbum']);
    Route::post('/admin/albums/{album}/tags/undo', [\App\Http\Controllers\Api\TagEditorController::class, 'undoAlbum']);
    Route::post('/admin/albums/{album}/cover', [\App\Http\Controllers\Api\TagEditorController::class, 'setAlbumCover']);
    Route::post('/admin/files/{file}/verify', [StorageController::class, 'verify']);
});
