<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\AdminToken;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MakesTaggedFiles;
use Tests\TestCase;

/** The edit_tags tool wraps the admin tag editor API (docs/api/tag-editor.md). */
class EditTagsMcpTest extends TestCase
{
    use MakesTaggedFiles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\App\Domain\Storage\TagWriter::available()) {
            $this->markTestSkipped('ffmpeg is not available.');
        }
        $this->makeBase();
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->removeBase();
        parent::tearDown();
    }

    private function asAdmin(): void
    {
        User::factory()->create();
        [, $plain] = AdminToken::issue('test');
        request()->headers->set('Authorization', 'Bearer '.$plain);
    }

    public function test_the_tool_is_only_listed_with_the_admin_token(): void
    {
        ReMomentServer::tool(Tools\EditTags::class, ['action' => 'show', 'file_id' => 1])->assertNotRegistered();
    }

    public function test_show_update_and_undo_a_file_and_an_album(): void
    {
        $library = $this->location('library', 'library');
        $this->makeAudio('library', 'a/1.mp3', ['title' => 'Raw', 'artist' => 'Band', 'album' => 'LP']);
        $this->makeAudio('library', 'a/2.mp3', ['title' => 'Raw 2', 'artist' => 'Band', 'album' => 'LP']);
        $this->scan($library);
        $file = File::orderBy('path')->first();
        $album = Album::firstOrFail();
        $this->asAdmin();

        ReMomentServer::tool(Tools\EditTags::class, ['action' => 'show', 'file_id' => $file->id])->assertOk()->assertSee('Raw');
        ReMomentServer::tool(Tools\EditTags::class, ['action' => 'update', 'file_id' => $file->id, 'tags' => ['title' => 'Fixed'], 'organize' => false])->assertOk()->assertSee('Fixed');
        $this->assertSame('Fixed', Track::where('album_id', $album->id)->orderBy('id')->first()->name);
        ReMomentServer::tool(Tools\EditTags::class, ['action' => 'undo', 'file_id' => $file->id])->assertOk();

        ReMomentServer::tool(Tools\EditTags::class, ['action' => 'update', 'album_id' => $album->id, 'tags' => ['year' => '2002']])->assertOk()->assertSee('edited');
        ReMomentServer::tool(Tools\EditTags::class, ['action' => 'album', 'album_id' => Track::first()->album_id])->assertOk()->assertSee('can_undo');
    }

    public function test_missing_arguments_and_api_errors_are_tool_errors(): void
    {
        $this->asAdmin();

        ReMomentServer::tool(Tools\EditTags::class, ['action' => 'show'])->assertHasErrors(['file_id or album_id is required']);
        ReMomentServer::tool(Tools\EditTags::class, ['action' => 'cover', 'file_id' => 1])->assertHasErrors(['image_base64 is required']);
        ReMomentServer::tool(Tools\EditTags::class, ['action' => 'undo', 'file_id' => 999])->assertHasErrors(['404']);
    }
}
