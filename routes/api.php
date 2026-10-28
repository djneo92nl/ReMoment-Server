<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\BeolinkStreamController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceSettingsController;
use App\Http\Controllers\Api\InfoController;
use App\Http\Controllers\Api\JellyfinController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\LiteArtworkController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\RemoteKeysController;
use App\Http\Controllers\Api\SourceControlsController;
use App\Http\Controllers\Api\SpotifyStreamController;
use App\Http\Controllers\Api\StorageController;
use App\Http\Controllers\Api\SyncGroupController;
use Illuminate\Support\Facades\Route;

Route::get('/info', InfoController::class);
Route::get('/alerts', [AlertController::class, 'index']);

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
Route::get('/devices/{device}/network', [DeviceSettingsController::class, 'getNetwork']);
Route::put('/devices/{device}/network/interface', [DeviceSettingsController::class, 'setNetworkInterface']);
Route::put('/devices/{device}/network/wired', [DeviceSettingsController::class, 'setWiredNetwork']);
Route::put('/devices/{device}/network/wifi', [DeviceSettingsController::class, 'joinWifi']);
Route::get('/devices/{device}/wired-speakers', [DeviceSettingsController::class, 'getWiredSpeakers']);
Route::put('/devices/{device}/wired-speakers/{speakerId}', [DeviceSettingsController::class, 'setWiredSpeaker'])->where('speakerId', '[A-Za-z0-9_]+');
Route::get('/devices/{device}/wireless-speakers', [DeviceSettingsController::class, 'getWirelessSpeakers']);
Route::post('/devices/{device}/wireless-speakers/scan', [DeviceSettingsController::class, 'scanWirelessSpeakers']);
Route::get('/devices/{device}/info', [DeviceSettingsController::class, 'getInfo']);
Route::put('/devices/{device}/info', [DeviceSettingsController::class, 'setInfo']);
Route::get('/devices/{device}/sleep-timer', [DeviceSettingsController::class, 'getSleepTimer']);
Route::put('/devices/{device}/sleep-timer', [DeviceSettingsController::class, 'setSleepTimer']);
Route::post('/devices/{device}/digits', [DeviceSettingsController::class, 'sendDigits']);
Route::get('/devices/{device}/keys', [RemoteKeysController::class, 'index']);
Route::post('/devices/{device}/keys', [RemoteKeysController::class, 'press']);
Route::get('/devices/{device}/jellyfin', [JellyfinController::class, 'index']);
Route::post('/devices/{device}/jellyfin/play', [JellyfinController::class, 'play']);

Route::get('/devices/{device}/sources', [DeviceController::class, 'sources']);
Route::post('/devices/{device}/sources/activate', [DeviceController::class, 'activateSource']);

Route::get('/devices/{device}/controls', [SourceControlsController::class, 'index']);
Route::post('/devices/{device}/controls/{control}', [SourceControlsController::class, 'run']);

Route::get('/spotify-stream', [SpotifyStreamController::class, 'show']);
Route::post('/devices/{device}/spotify-stream', [SpotifyStreamController::class, 'play']);
Route::post('/librespot/event', [SpotifyStreamController::class, 'event']);

Route::get('/beolink-stream', [BeolinkStreamController::class, 'show']);
Route::post('/devices/{device}/beolink-stream', [BeolinkStreamController::class, 'play']);
Route::delete('/devices/{device}/beolink-stream', [BeolinkStreamController::class, 'stop']);

Route::get('/devices/{device}/radio', [DeviceController::class, 'radioStations']);
Route::get('/radio/{station}/now', [DeviceController::class, 'radioNow']);
Route::put('/radio/{station}/favorite', [DeviceController::class, 'favoriteRadio']);
Route::post('/devices/{device}/radio/{station}', [DeviceController::class, 'playRadio']);

Route::get('/devices/{device}/multiroom', [DeviceController::class, 'multiroom']);
Route::post('/devices/{device}/multiroom/join', [DeviceController::class, 'multiroomJoin']);
Route::delete('/devices/{device}/multiroom/leave', [DeviceController::class, 'multiroomLeave']);

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
Route::post('/devices/{device}/library/play-album', [DeviceController::class, 'libraryPlayAlbum']);
Route::post('/devices/{device}/library/play-artist', [DeviceController::class, 'libraryPlayArtist']);

Route::get('/library/libraries', [LibraryController::class, 'libraries']);
Route::get('/library/recent-items', [LibraryController::class, 'recentItems']);
Route::get('/library/artists', [LibraryController::class, 'artists']);
Route::get('/library/artists/{artist}', [LibraryController::class, 'artist']);
Route::get('/library/artists/{artist}/discography', [LibraryController::class, 'discography']);
Route::get('/library/wishlist', [LibraryController::class, 'wishlist']);
Route::get('/library/catalogue/{catalogue_album}', [LibraryController::class, 'catalogueAlbum']);
Route::put('/library/catalogue/{catalogue_album}/wanted', [LibraryController::class, 'wantCatalogueAlbum']);
Route::put('/library/catalogue/{catalogue_album}/ignored', [LibraryController::class, 'ignoreCatalogueAlbum']);
Route::put('/library/artists/{artist}/favorite', [LibraryController::class, 'favoriteArtist']);
Route::get('/library/tracks', [LibraryController::class, 'tracks']);
Route::get('/library/albums', [LibraryController::class, 'albums']);
Route::get('/library/random-album', [LibraryController::class, 'randomAlbum']);
Route::get('/library/albums/{album}', [LibraryController::class, 'album']);
Route::put('/library/albums/{album}/favorite', [LibraryController::class, 'favoriteAlbum']);
Route::get('/library/albums/{album}/spotify', [LibraryController::class, 'albumSpotify']);
Route::post('/library/albums/{album}/fill-from-spotify', [LibraryController::class, 'fillAlbumFromSpotify']);
Route::put('/library/tracks/{track}/favorite', [LibraryController::class, 'favoriteTrack']);
Route::get('/library/tracks/{track}/waveform', [LibraryController::class, 'waveform']);
Route::get('/library/listening-modes', [LibraryController::class, 'listeningModes']);
Route::get('/library/health', [LibraryController::class, 'health']);
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

// Admin token login for apps. Routes that change configuration go in the `admin.token` group (see docs/api/admin-auth.md).
Route::post('/admin/login', [AdminAuthController::class, 'login']);
Route::middleware('admin.token')->group(function () {
    Route::get('/admin/me', [AdminAuthController::class, 'me']);
    Route::delete('/admin/token', [AdminAuthController::class, 'logout']);
    Route::post('/admin/alerts/{alert}/ack', [AlertController::class, 'acknowledge']);

    // Storage locations and the library's files (docs/architecture/file-storage.md)
    Route::get('/admin/storage/locations', [StorageController::class, 'locations']);
    Route::post('/admin/storage/locations', [StorageController::class, 'storeLocation']);
    Route::put('/admin/storage/locations/{location}', [StorageController::class, 'updateLocation']);
    Route::delete('/admin/storage/locations/{location}', [StorageController::class, 'destroyLocation']);
    Route::post('/admin/storage/locations/{location}/test', [StorageController::class, 'testLocation']);
    Route::post('/admin/storage/locations/{location}/scan', [StorageController::class, 'scan']);
    Route::post('/admin/storage/import', [StorageController::class, 'import']);
    Route::post('/admin/storage/reorganize', [StorageController::class, 'reorganize']);
    Route::post('/admin/files', [StorageController::class, 'upload']);
    Route::get('/admin/tracks/{track}/files', [StorageController::class, 'trackFiles']);
    Route::delete('/admin/tracks/{track}/files', [StorageController::class, 'destroyTrack']);
    Route::delete('/admin/files/{file}', [StorageController::class, 'destroyFile']);
    // Device source visibility (the web UI's DeviceSourceManager)
    Route::put('/admin/devices/{device}/sources/{sourceId}', [DeviceController::class, 'updateSource'])->where('sourceId', '[^/]+');
    Route::post('/admin/files/{file}/move', [StorageController::class, 'move']);
    Route::put('/admin/files/{file}/tags', [StorageController::class, 'tags']);
    Route::post('/admin/files/{file}/verify', [StorageController::class, 'verify']);
});
