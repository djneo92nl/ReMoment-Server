<?php

namespace App\Http\Controllers;

use App\Domain\Party\Party;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** The party page behind a device's party link (docs/frontend/party.md): guests add tracks to its queue. */
class PartyController extends Controller
{
    public const COOKIE = 'remoment_party_visitor';

    public function __invoke(Request $request, string $token): Response
    {
        $device = Party::deviceForToken($token);
        abort_unless($device, 404);

        // No accounts: a visitor is a random cookie, only used to count their adds.
        $visitor = (string) $request->cookie(self::COOKIE) ?: Str::random(32);

        return response()
            ->view('party', ['device' => $device, 'token' => $token, 'visitor' => $visitor])
            ->cookie(self::COOKIE, $visitor, 60 * 24);
    }
}
