<?php

namespace App\Http\Controllers\Api;

use App\Domain\Push\Push;
use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Browsers register and remove their own Web Push subscription; open like the rest of the API, no accounts (docs/frontend/pwa.md). */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => 'required|string|url:https|max:700',
            'keys' => 'required|array',
            'keys.p256dh' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'keys.auth' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        if (!Push::allowedEndpoint($data['endpoint'])) {
            return response()->json(['error' => 'invalid', 'message' => 'The endpoint is not a known browser push service.'], 422);
        }

        $existing = PushSubscription::where('endpoint', $data['endpoint'])->first();
        if (!$existing && PushSubscription::count() >= (int) config('push.max_subscriptions', 100)) {
            return response()->json(['error' => 'too_many', 'message' => 'The server stores no more push subscriptions.'], 422);
        }

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            ['p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth'], 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null],
        );

        return response()->json(['status' => 'ok'], $subscription->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => 'required|string|max:700']);

        PushSubscription::where('endpoint', $data['endpoint'])->delete();

        return response()->json(['status' => 'ok']);
    }
}
