<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_jobs', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('role', 20)->nullable();
            $table->string('type', 40);
            $table->json('params')->nullable();
            $table->string('state', 20)->default('queued');
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->json('result')->nullable();
            $table->string('error', 500)->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'idempotency_key']);
            $table->index(['client_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_jobs');
    }
};
