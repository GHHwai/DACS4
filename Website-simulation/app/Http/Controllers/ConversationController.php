<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConversationController extends Controller
{
    public function index(Request $request, ChatService $chat): JsonResponse
    {
        return response()->json(['conversations' => $chat->summaries($request->user())]);
    }

    public function show(Request $request, Conversation $conversation, ChatService $chat): JsonResponse
    {
        Gate::authorize('view', $conversation);

        return response()->json($chat->details($request->user(), $conversation));
    }

    /** Open (or create) the 1:1 chat with a friend. */
    public function direct(Request $request, ChatService $chat): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $conversation = $chat->directConversation($request->user(), User::findOrFail($data['user_id']));

        return response()->json(['conversation' => $chat->summary($request->user(), $conversation)]);
    }

    public function messages(Request $request, Conversation $conversation, ChatService $chat): JsonResponse
    {
        Gate::authorize('view', $conversation);
        $data = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($chat->history(
            $request->user(), $conversation,
            isset($data['before_id']) ? (int) $data['before_id'] : null,
            isset($data['limit']) ? (int) $data['limit'] : null,
        ));
    }

    public function read(Request $request, Conversation $conversation, ChatService $chat): JsonResponse
    {
        Gate::authorize('view', $conversation);
        $data = $request->validate(['message_id' => ['required', 'integer', 'min:1']]);
        $chat->markRead($request->user(), $conversation, (int) $data['message_id']);

        return response()->json(['ok' => true]);
    }
}
