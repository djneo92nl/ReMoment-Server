<?php

namespace Tests\Unit;

use App\Domain\Availability\Providers;
use App\Domain\Library\LibrarySources;
use Tests\TestCase;

/** The provider registry (config/availability.php): ownership per provider, in step with LibrarySources. */
class AvailabilityProvidersTest extends TestCase
{
    public function test_every_library_has_a_provider(): void
    {
        foreach (LibrarySources::libraryIds() as $id) {
            $this->assertTrue(Providers::known($id), "library {$id} needs a provider in config/availability.php");
        }
    }

    public function test_ownership_is_the_providers_not_the_librarys_kind(): void
    {
        $this->assertSame('streaming', LibrarySources::LIBRARIES['dlna']['kind']);
        $this->assertTrue(Providers::isOwned('dlna'), 'DLNA serves the household\'s own files');
        $this->assertTrue(Providers::isOwned('files'));
        $this->assertTrue(Providers::isOwned('physical'));
        $this->assertFalse(Providers::isOwned('spotify'));
        $this->assertFalse(Providers::isOwned('deezer'), 'an unknown provider is never owned');
        $this->assertTrue(Providers::isOwned('dlna:3'), 'an instance suffix is ignored');
    }

    public function test_owned_and_streaming_libraries_split_the_registry(): void
    {
        $this->assertEqualsCanonicalizing(['files', 'dlna'], Providers::ownedLibraries());
        $this->assertSame([], Providers::streamingLibraries(), 'Spotify extends the library live, it is not a library');
        $this->assertTrue(Providers::anyOwned(['spotify', 'dlna:2']));
        $this->assertFalse(Providers::anyOwned(['spotify']));
    }
}
