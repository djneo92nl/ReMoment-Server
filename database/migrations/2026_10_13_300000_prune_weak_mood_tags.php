<?php

use App\Models\FileAnalysis;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Mood tags stored before `analysis.mood_min_score` existed (0.5 and up) lose those under the cut-off. */
    public function up(): void
    {
        $minimum = (float) config('analysis.mood_min_score', 0.7);

        FileAnalysis::query()->whereNotNull('mood_tags')->each(function (FileAnalysis $analysis) use ($minimum) {
            $kept = array_values(array_filter($analysis->mood_tags ?? [], fn ($tag) => ($tag['score'] ?? 0) >= $minimum));

            if (count($kept) !== count($analysis->mood_tags ?? [])) {
                $analysis->update(['mood_tags' => $kept ?: null]);
            }
        });
    }

    public function down(): void
    {
        // The dropped tags were weak guesses; nothing to restore.
    }
};
