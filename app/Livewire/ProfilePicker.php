<?php

namespace App\Livewire;

use App\Domain\Profile\Profile;
use App\Models\Setting;
use Livewire\Component;

/**
 * The three profile cards (Control, Standard, Full) with what each runs and needs. The choice is a Setting
 * (`profile_chosen`); the profile that actually runs is PROFILE in the environment, which wins: when the two differ the
 * component says what to change. Used by the setup wizard and /settings/profile (admin).
 */
class ProfilePicker extends Component
{
    public const SETTING = 'profile_chosen';

    public function choose(string $profile): void
    {
        abort_unless(auth()->check(), 403);

        if (in_array($profile, Profile::ALL, true)) {
            Setting::set(self::SETTING, $profile);
        }
    }

    /** The profile the admin picked, null while they have not. */
    public static function chosen(): ?string
    {
        $value = Setting::get(self::SETTING);

        return in_array($value, Profile::ALL, true) ? $value : null;
    }

    public function render()
    {
        $running = Profile::current();
        $chosen = self::chosen();

        return view('livewire.profile-picker', [
            'running' => $running,
            'chosen' => $chosen,
            'cards' => config('profile.profiles'),
            'mismatch' => $chosen !== null && $chosen !== $running,
            'upgrade' => $chosen !== null && array_search($chosen, Profile::ALL, true) > array_search($running, Profile::ALL, true),
            'canChoose' => auth()->check(),
            'composeProfiles' => $chosen ? config("profile.compose_profiles.{$chosen}") : [],
        ]);
    }
}
