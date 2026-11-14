<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // 'client' (every existing row) or 'node' (workstream 18); separate from `type`, which is about players.
            $table->string('kind', 20)->default('client')->after('type');
            $table->json('roles')->nullable()->after('kind');
            $table->json('enabled_roles')->nullable()->after('roles');
            $table->json('capabilities')->nullable()->after('enabled_roles');
            $table->json('node_status')->nullable()->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['kind', 'roles', 'enabled_roles', 'capabilities', 'node_status']);
        });
    }
};
