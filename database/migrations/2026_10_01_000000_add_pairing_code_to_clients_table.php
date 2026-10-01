<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('pairing_code', 8)->nullable()->unique()->after('registration_token');
        });

        // Backfill existing clients so every row has a code.
        $used = [];
        foreach (DB::table('clients')->whereNull('pairing_code')->pluck('id') as $id) {
            do {
                $code = '';
                for ($i = 0; $i < 6; $i++) {
                    $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
                }
            } while (isset($used[$code]));

            $used[$code] = true;
            DB::table('clients')->where('id', $id)->update(['pairing_code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['pairing_code']);
            $table->dropColumn('pairing_code');
        });
    }
};
