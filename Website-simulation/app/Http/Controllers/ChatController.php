<?php

namespace App\Http\Controllers;

use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function index(Request $request): View
    {
        return view('chat', [
            'config' => [
                'me' => $request->user()->toPublic(),
                'wsPort' => (int) config('chat.port'),
                'maxUploadKb' => (int) config('chat.max_upload_kb'),
                'maxFiles' => (int) config('chat.max_files_per_message'),
                'maxMessage' => (int) config('chat.max_message_length'),
            ],
        ]);
    }

    /** Everything the UI needs on first load (and after a socket reconnect). */
    public function bootstrap(Request $request, ChatService $chat, FriendController $friends): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'me' => $user->toPublic() + ['email' => $user->email],
            'conversations' => $chat->summaries($user),
            'friends' => $friends->lists($user),
        ]);
    }
}
