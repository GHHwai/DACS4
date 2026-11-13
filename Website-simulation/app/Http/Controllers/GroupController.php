<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class GroupController extends Controller
{
    public function store(Request $request, ChatService $chat): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'member_ids' => ['required', 'array', 'min:1', 'max:'.(config('chat.group_max_members') - 1)],
            'member_ids.*' => ['integer'],
        ]);
        $group = $chat->createGroup($request->user(), trim($data['name']), $data['member_ids']);

        return response()->json(['conversation' => $chat->details($request->user(), $group)], 201);
    }

    public function update(Request $request, Conversation $conversation, ChatService $chat): JsonResponse
    {
        Gate::authorize('manage', $conversation);
        $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:80']]);
        $chat->rename($request->user(), $conversation, trim($data['name']));

        return response()->json($chat->details($request->user(), $conversation->refresh()));
    }

    public function addMembers(Request $request, Conversation $conversation, ChatService $chat): JsonResponse
    {
        Gate::authorize('manage', $conversation);
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:50'],
            'user_ids.*' => ['integer'],
        ]);
        $chat->addMembers($request->user(), $conversation, $data['user_ids']);

        return response()->json($chat->details($request->user(), $conversation->refresh()));
    }

    public function removeMember(Request $request, Conversation $conversation, User $user, ChatService $chat): JsonResponse
    {
        Gate::authorize('manage', $conversation);
        $chat->removeMember($request->user(), $conversation, $user);

        return response()->json($chat->details($request->user(), $conversation->refresh()));
    }

    public function setRole(Request $request, Conversation $conversation, User $user, ChatService $chat): JsonResponse
    {
        Gate::authorize('own', $conversation);
        $data = $request->validate(['role' => ['required', 'in:admin,member']]);
        $chat->setRole($conversation, $user, $data['role']);

        return response()->json($chat->details($request->user(), $conversation->refresh()));
    }

    public function transfer(Request $request, Conversation $conversation, ChatService $chat): JsonResponse
    {
        Gate::authorize('own', $conversation);
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $chat->transferOwnership($request->user(), $conversation, User::findOrFail($data['user_id']));

        return response()->json($chat->details($request->user(), $conversation->refresh()));
    }

    public function leave(Request $request, Conversation $conversation, ChatService $chat): JsonResponse
    {
        Gate::authorize('leave', $conversation);
        $chat->leave($request->user(), $conversation);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, Conversation $conversation, ChatService $chat): JsonResponse
    {
        Gate::authorize('own', $conversation);
        $chat->deleteGroup($conversation);

        return response()->json(['ok' => true]);
    }
}
