<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plays', function (Blueprint $table) {
            $table->dropForeign(['track_id']);
            $table->unsignedBigInteger('track_id')->nullable()->change();
            $table->foreign('track_id')->references('id')->on('tracks')->nullOnDelete();

            $table->string('source_type')->nullable()->after('track_id');
            $table->string('radio_name')->nullable()->after('source_type');
            $table->string('source_name')->nullable()->after('radio_name');
            $table->timestamp('ended_at')->nullable()->after('played_at');

            $table->index('source_type');
        });
    }

    public function down(): void
    {
        Schema::table('plays', function (Blueprint $table) {
            $table->dropIndex(['source_type']);
            $table->dropColumn(['source_type', 'radio_name', 'source_name', 'ended_at']);

            $table->dropForeign(['track_id']);
            $table->unsignedBigInteger('track_id')->nullable(false)->change();
            $table->foreign('track_id')->references('id')->on('tracks')->cascadeOnDelete();
        });
    }
};
