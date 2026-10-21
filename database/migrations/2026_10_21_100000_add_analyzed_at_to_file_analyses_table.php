<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When a stage of this file last finished (success or failure): throughput and "what ran lately" on /settings/analysis.
        Schema::table('file_analyses', function (Blueprint $table) {
            $table->timestamp('analyzed_at')->nullable()->index()->after('errors');
        });
    }

    public function down(): void
    {
        Schema::table('file_analyses', function (Blueprint $table) {
            $table->dropColumn('analyzed_at');
        });
    }
};
