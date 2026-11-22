<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Undo of the last tag edit of an own file (workstream 8.4, App\Domain\Storage\TagEditor): the tags the file had before,
    // whether it had an embedded cover (its bytes are kept on the private disk under tag-snapshots/), the path it had, and the
    // hash the file got from the edit, so an undo is refused once the file changed again. One row per file: only the last
    // edit can be undone. Deleting the file deletes its snapshot.
    public function up(): void
    {
        Schema::create('file_tag_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->unique()->constrained('files')->cascadeOnDelete();
            $table->json('tags');                       // field => previous value, null when the file had none
            $table->boolean('had_cover')->default(false);
            $table->boolean('cover_changed')->default(false); // the edit replaced or removed the cover
            $table->string('path_before', 1000);
            $table->string('hash_after', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_tag_snapshots');
    }
};
