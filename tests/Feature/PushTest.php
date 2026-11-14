<?php

namespace Tests\Feature;

use App\Domain\Alerts\Alerts;
use App\Domain\Credentials\ServiceCredentials;
use App\Domain\Push\PushResult;
use App\Domain\Push\Vapid;
use App\Models\PushSubscription;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakePushSender;
use Tests\TestCase;

class PushTest extends TestCase
{
    use RefreshDatabase;

    private FakePushSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sender = new FakePushSender;
        $this->app->instance(\App\Domain\Push\PushSender::class, $this->sender);
    }

    private function sub(string $host = 'fcm.googleapis.com', string $path = '/fcm/send/a'): PushSubscription
    {
        return PushSubscription::create(['endpoint' => "https://{$host}{$path}", 'p256dh' => 'BKey', 'auth' => 'authkey']);
    }

    private function body(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc'): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg']];
    }

    // ---- VAPID

    public function test_vapid_pair_is_made_once_with_an_encrypted_private_key(): void
    {
        $this->assertFalse(Vapid::exists());
        $public = Vapid::publicKey();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{80,}$/', $public);
        $this->assertSame($public, Vapid::publicKey());
        $stored = (string) Setting::get(Vapid::PRIVATE_SETTING);
        $this->assertSame(Vapid::privateKey(), \Illuminate\Support\Facades\Crypt::decryptString($stored));
        $this->assertStringNotContainsString((string) Vapid::privateKey(), $stored);
        $this->assertContains(Vapid::PRIVATE_SETTING, ServiceCredentials::secretSettingKeys());
        $this->assertTrue(\App\Domain\Backup\Snapshot::isSecretSetting(Vapid::PRIVATE_SETTING));
        $this->assertFalse(\App\Domain\Backup\Snapshot::isSecretSetting(Vapid::PUBLIC_SETTING));
    }

    public function test_push_vapid_command_shows_only_the_public_key_and_rotate_drops_subscriptions(): void
    {
        $this->artisan('push:vapid')->expectsOutputToContain('Public key: ')->assertSuccessful();
        $this->sub();
        $private = Vapid::privateKey();
        $public = Vapid::publicKey();
        $this->assertSame(1, PushSubscription::count());

        $this->artisan('push:vapid')->doesntExpectOutputToContain((string) $private)->assertSuccessful();
        $this->assertSame($public, Vapid::publicKey());

        $this->artisan('push:vapid --rotate --force')->assertSuccessful();
        $this->assertNotSame($public, Vapid::publicKey());
        $this->assertSame(0, PushSubscription::count());
    }

    // ---- API

    public function test_subscribe_stores_one_row_per_endpoint_and_unsubscribe_removes_it(): void
    {
        $this->postJson('/api/push/subscribe', $this->body())->assertCreated();
        $this->postJson('/api/push/subscribe', $this->body())->assertOk();
        $this->assertSame(1, PushSubscription::count());
        $this->assertNull(PushSubscription::first()->getAttribute('user_id'));

        $this->deleteJson('/api/push/subscribe', ['endpoint' => $this->body()['endpoint']])->assertOk();
        $this->assertSame(0, PushSubscription::count());
        $this->deleteJson('/api/push/subscribe', ['endpoint' => 'https://fcm.googleapis.com/x'])->assertOk();
    }

    public function test_subscribe_refuses_what_is_not_a_browser_push_service(): void
    {
        foreach (['http://fcm.googleapis.com/x', 'https://192.168.1.5/x', 'https://evil.example/x', 'https://notgoogleapis.com/x', 'https://fcm.googleapis.com:8443/x', 'ftp://fcm.googleapis.com/x'] as $endpoint) {
            $this->postJson('/api/push/subscribe', $this->body($endpoint))->assertStatus(422);
        }
        $this->postJson('/api/push/subscribe', $this->body('https://updates.push.services.mozilla.com/wpush/v2/a'))->assertCreated();
        $this->postJson('/api/push/subscribe', $this->body('https://web.push.apple.com/a'))->assertCreated();
        $this->postJson('/api/push/subscribe', ['endpoint' => 'https://fcm.googleapis.com/x', 'keys' => ['p256dh' => 'not base64!', 'auth' => 'a']])->assertStatus(422);
        $this->assertSame(2, PushSubscription::count());
    }

    public function test_subscribe_is_capped_and_rate_limited(): void
    {
        config(['push.max_subscriptions' => 1]);
        $this->postJson('/api/push/subscribe', $this->body())->assertCreated();
        $this->postJson('/api/push/subscribe', $this->body('https://fcm.googleapis.com/other'))->assertStatus(422)->assertJsonPath('error', 'too_many');
        $this->postJson('/api/push/subscribe', $this->body())->assertOk(); // an existing one still refreshes

        config(['push.max_subscriptions' => 100]);
        $status = 200;
        for ($i = 0; $i < 25 && $status !== 429; $i++) {
            $status = $this->deleteJson('/api/push/subscribe', ['endpoint' => 'https://fcm.googleapis.com/x'])->status();
        }
        $this->assertSame(429, $status);
    }

    // ---- Delivery from alerts

    public function test_warning_and_critical_alerts_are_pushed_but_info_is_not(): void
    {
        $this->sub();
        Alerts::raise('a.info', 'k', 'info', 'Info', 'm');
        $this->assertCount(0, $this->sender->sent);

        $alert = Alerts::raise('a.warn', 'k', 'warning', 'Queue stuck', 'Jobs wait');
        $this->assertCount(1, $this->sender->sent);
        $this->assertSame([
            'id' => $alert->id, 'severity' => 'warning', 'title' => 'Queue stuck', 'body' => 'Jobs wait',
            'tag' => 'remoment-alert-'.$alert->id, 'url' => '/settings/health',
        ], $this->sender->sent[0]['payload']);
        $this->assertNotNull(PushSubscription::first()->last_success_at);
    }

    public function test_an_alert_is_pushed_once_per_severity(): void
    {
        $this->sub();
        Alerts::raise('a.x', 'k', 'warning', 'T', 'm');
        Alerts::raise('a.x', 'k', 'warning', 'T', 'changed message');
        $this->assertCount(1, $this->sender->sent);

        Alerts::raise('a.x', 'k', 'critical', 'T', 'worse');
        $this->assertCount(2, $this->sender->sent);
        $this->assertSame('critical', $this->sender->sent[1]['payload']['severity']);

        // A retry of the same job does not send again.
        (new \App\Jobs\SendAlertPush(Alerts::active()->first()->id, 'critical'))->handle();
        $this->assertCount(2, $this->sender->sent);
    }

    public function test_nothing_is_queued_without_subscriptions_and_resolved_alerts_are_not_pushed(): void
    {
        Alerts::raise('a.x', 'k', 'critical', 'T', 'm');
        $this->assertCount(0, $this->sender->sent);

        $alert = Alerts::active()->first();
        Alerts::resolve('a.x', 'k');
        Cache::flush();
        $this->sub();
        (new \App\Jobs\SendAlertPush($alert->id, 'critical'))->handle();
        $this->assertCount(0, $this->sender->sent);
    }

    public function test_gone_subscriptions_are_removed_and_failures_never_stop_the_alert(): void
    {
        $gone = $this->sub('fcm.googleapis.com', '/gone');
        $boom = $this->sub('fcm.googleapis.com', '/boom');
        $fine = $this->sub('fcm.googleapis.com', '/fine');
        $failing = $this->sub('fcm.googleapis.com', '/failing');
        $this->sender->results[$gone->endpoint] = new PushResult(false, true, 'Gone');
        $this->sender->results[$boom->endpoint] = new \RuntimeException('network down');
        $this->sender->results[$failing->endpoint] = new PushResult(false, false, 'Server error');

        $alert = Alerts::raise('a.x', 'k', 'critical', 'T', 'm');

        $this->assertNotNull($alert);
        $this->assertSame(1, \App\Models\Alert::count());
        $this->assertSame(4, count($this->sender->sent));
        $this->assertNull(PushSubscription::find($gone->id));
        $this->assertNotNull(PushSubscription::find($boom->id));
        $this->assertNotNull(PushSubscription::find($failing->id));
        $this->assertNotNull(PushSubscription::find($fine->id)->last_success_at);
    }

    public function test_a_broken_sender_binding_does_not_stop_raising(): void
    {
        $this->sub();
        $this->app->bind(\App\Domain\Push\PushSender::class, fn () => throw new \RuntimeException('no sender'));

        $this->assertNotNull(Alerts::raise('a.x', 'k', 'critical', 'T', 'm'));
        $this->assertSame(1, \App\Models\Alert::count());
    }

    // ---- push:test

    public function test_push_test_sends_to_every_browser_without_raising_an_alert(): void
    {
        $this->artisan('push:test')->expectsOutputToContain('No browser has turned push on')->assertSuccessful();

        $this->sub();
        $this->artisan('push:test --message=Hello')->expectsOutputToContain('Sent 1 of 1')->assertSuccessful();
        $this->assertSame('Hello', $this->sender->sent[0]['payload']['body']);
        $this->assertSame(0, \App\Models\Alert::count());
    }

    // ---- Browser side (no JS tests in the project: pin the text)

    public function test_service_worker_handles_push_and_the_page_offers_the_background_toggle(): void
    {
        $worker = $this->get('/sw.js')->getContent();
        $this->assertStringContainsString("addEventListener('push'", $worker);
        $this->assertStringContainsString('showNotification', $worker);
        $this->assertStringNotContainsString('cache.put', $worker); // still caches nothing but the offline shell

        $this->actingAs(\App\Models\User::factory()->create())->get('/settings/health')
            ->assertOk()
            ->assertSee('data-test="push-notifications-toggle"', false)
            ->assertSee('data-vapid-key="'.Vapid::publicKey().'"', false)
            ->assertSee('installed first', false);
    }
}
