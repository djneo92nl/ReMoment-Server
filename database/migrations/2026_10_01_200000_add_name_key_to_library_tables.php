<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Normalized comparison keys (App\Domain\Library\Normalizer) so one artist,
 * album or track is found again whatever source names it. Schema only:
 * existing rows get their keys from `library:merge-duplicates` or lazily on
 * the first lookup that misses (LibraryIdentity), never from this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            $table->string('name_key')->nullable()->after('name')->index();
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->string('name_key')->nullable()->after('name');
            $table->index(['artist_id', 'name_key']);
        });

        Schema::table('tracks', function (Blueprint $table) {
            $table->string('name_key')->nullable()->after('name');
            $table->index(['album_id', 'name_key']);
            $table->index(['artist_id', 'name_key']);
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropIndex(['album_id', 'name_key']);
            $table->dropIndex(['artist_id', 'name_key']);
            $table->dropColumn('name_key');
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->dropIndex(['artist_id', 'name_key']);
            $table->dropColumn('name_key');
        });

        Schema::table('artists', function (Blueprint $table) {
            $table->dropIndex(['name_key']);
            $table->dropColumn('name_key');
        });
    }
};
