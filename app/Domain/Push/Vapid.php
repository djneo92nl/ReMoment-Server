<?php

namespace App\Domain\Push;

use App\Models\PushSubscription;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Minishlink\WebPush\VAPID as WebPushVapid;

/**
 * The server's VAPID key pair, generated once and kept in the settings table: the public key in clear (browsers need it),
 * the private key encrypted with the APP_KEY and left out of backups and diagnostics like every secret
 * (`ServiceCredentials::secretSettingKeys()`). Changing the pair invalidates every stored subscription.
 */
final class Vapid
{
    public const PUBLIC_SETTING = 'push_vapid_public';

    public const PRIVATE_SETTING = 'push_vapid_private';

    public static function exists(): bool
    {
        return self::privateKey() !== null && (string) Setting::get(self::PUBLIC_SETTING, '') !== '';
    }

    /** The public key (base64url) for `applicationServerKey`; creates the pair on first use. */
    public static function publicKey(): string
    {
        self::ensure();

        return (string) Setting::get(self::PUBLIC_SETTING);
    }

    /** The private key, or null when there is none or it cannot be decrypted (APP_KEY changed). */
    public static function privateKey(): ?string
    {
        $stored = (string) Setting::get(self::PRIVATE_SETTING, '');
        if ($stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Creates the pair when missing (or unreadable). Returns true when it made a new one. */
    public static function ensure(): bool
    {
        if (self::exists()) {
            return false;
        }

        self::replace();

        return true;
    }

    /** A new pair; the old subscriptions belong to the old key and are deleted. */
    public static function replace(): void
    {
        $keys = WebPushVapid::createVapidKeys();
        Setting::set(self::PUBLIC_SETTING, $keys['publicKey']);
        Setting::set(self::PRIVATE_SETTING, Crypt::encryptString($keys['privateKey']));
        PushSubscription::query()->delete();
    }

    /** @return array{subject: string, publicKey: string, privateKey: string} */
    public static function auth(): array
    {
        self::ensure();

        return ['subject' => (string) config('push.subject'), 'publicKey' => (string) Setting::get(self::PUBLIC_SETTING), 'privateKey' => (string) self::privateKey()];
    }
}
