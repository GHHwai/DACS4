<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MessageController extends Controller
{
    /** Text and/or attachments (multipart). Plain text may also go over the socket. */
    public function store(Request $request, Conversation $conversation, ChatService $chat): JsonResponse
    {
        Gate::authorize('send', $conversation);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:'.config('chat.max_message_length')],
            'files' => ['nullable', 'array', 'max:'.config('chat.max_files_per_message')],
            'files.*' => ['file', 'max:'.config('chat.max_upload_kb')],
        ]);

        $message = $chat->send($request->user(), $conversation, $data['body'] ?? null, $request->file('files', []));

        return response()->json(['message' => $message->toPayload()], 201);
    }

    public function destroy(Message $message, ChatService $chat): JsonResponse
    {
        Gate::authorize('delete', $message);
        $chat->deleteMessage($message);

        return response()->json(['ok' => true]);
    }
}
