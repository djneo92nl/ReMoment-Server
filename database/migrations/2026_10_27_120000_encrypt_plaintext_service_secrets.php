<?php

use App\Domain\Credentials\ServiceCredentials;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Secret credential rows stored as plain text (by hand, or by an older version) are encrypted (docs/architecture/credentials.md). */
    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            ServiceCredentials::encryptPlaintext();
        }
    }

    public function down(): void {}
};
