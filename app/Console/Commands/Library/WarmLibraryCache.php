<?php

namespace App\Console\Commands\Library;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibraryHome;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;

/**
 * Fills and refreshes the cached library overviews (LibraryCache) so a visit never waits for them: the scheduler
 * runs it every few minutes, and a visitor then finds every entry fresh or, at worst, stale and served at once.
 */
class WarmLibraryCache extends Command
{
    protected $signature = 'library:warm';

    protected $description = 'Fill and refresh the cached library overviews (library page, libraries, genres and playlists of the API)';

    public function handle(Kernel $http): int
    {
        $started = microtime(true);

        foreach (LeadingSource::SCOPES as $scope) {
            LibraryHome::build($scope, LeadingSource::hidden($scope));

            foreach (['/api/library/genres', '/api/library/playlists'] as $path) {
                $this->get($http, "{$path}?scope={$scope}");
            }
            $this->line("Warmed {$scope}.");
        }
        $this->get($http, '/api/library/libraries');

        // A stale entry is served as it is and refreshed after the response; there is no response here to wait for.
        app(DeferredCallbackCollection::class)->invoke();

        $this->info(sprintf('Done in %.1fs.', microtime(true) - $started));

        return self::SUCCESS;
    }

    private function get(Kernel $http, string $uri): void
    {
        $http->handle(Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'application/json']));
    }
}
