<?php

namespace App\Http\Controllers;

use App\Models\ChatToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeController extends Controller
{
    /**
     * Issue a short-lived credential for the TCP / WebSocket gateway. The browser
     * calls this before every (re)connect so a leaked token expires in minutes.
     */
    public function token(Request $request): JsonResponse
    {
        $ttl = (int) config('chat.token_ttl');

        return response()->json([
            'token' => ChatToken::issue($request->user(), $ttl),
            'expires_in' => $ttl,
            'port' => (int) config('chat.port'),
        ]);
    }
}
