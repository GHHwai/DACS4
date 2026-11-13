<?php

namespace App\Services;

use App\Contracts\SpamDetector;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Realtime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Every chat mutation goes through here, whether it came from a REST request
 * or from the TCP / WebSocket gateway, so the rules are enforced in one place.
 */
class ChatService
{
    public function __construct(private SpamDetector $spam)
    {
    }

    /* ------------------------------------------------------------------ */
    /* Conversations                                                        */
    /* ------------------------------------------------------------------ */

    public function directConversation(User $a, User $b): Conversation
    {
        if ($a->id === $b->id) {
            throw ValidationException::withMessages(['user_id' => 'You cannot start a chat with yourself.']);
        }
        if (! $a->isFriendWith((int) $b->id)) {
            throw new AuthorizationException('You can only chat with your friends.');
        }

        return DB::transaction(function () use ($a, $b) {
            $conversation = Conversation::firstOrCreate(
                ['direct_key' => Conversation::directKey((int) $a->id, (int) $b->id)],
                ['type' => Conversation::DIRECT],
            );

            if ($conversation->wasRecentlyCreated) {
                $this->attach($conversation, (int) $a->id, Conversation::ROLE_MEMBER, 0);
                $this->attach($conversation, (int) $b->id, Conversation::ROLE_MEMBER, 0);
            }

            return $conversation;
        });
    }

    /** @param  array<int|string>  $memberIds */
    public function createGroup(User $owner, string $name, array $memberIds): Conversation
    {
        $ids = $this->friendsOnly($owner, $memberIds, 'member_ids');
        if (count($ids) < 1) {
            throw ValidationException::withMessages(['member_ids' => 'Pick at least one friend to start a group.']);
        }
        if (count($ids) + 1 > (int) config('chat.group_max_members')) {
            throw ValidationException::withMessages(['member_ids' => 'Too many members for one group.']);
        }

        return DB::transaction(function () use ($owner, $name, $ids) {
            $group = Conversation::create([
                'type' => Conversation::GROUP,
                'name' => $name,
                'owner_id' => $owner->id,
            ]);
            $this->attach($group, (int) $owner->id, Conversation::ROLE_OWNER, 0);
            foreach ($ids as $id) {
                $this->attach($group, $id, Conversation::ROLE_MEMBER, 0);
            }
            $this->system($group, "{$owner->name} created the group");

            return $group;
        });
    }

    /** @param  array<int|string>  $userIds */
    public function addMembers(User $actor, Conversation $group, array $userIds): void
    {
        $this->assertGroup($group);
        $existing = $group->memberIds();
        $ids = array_values(array_diff($this->friendsOnly($actor, $userIds, 'user_ids'), $existing));

        if (! $ids) {
            throw ValidationException::withMessages(['user_ids' => 'Nobody new to add.']);
        }
        if (count($existing) + count($ids) > (int) config('chat.group_max_members')) {
            throw ValidationException::withMessages(['user_ids' => 'The group is full.']);
        }

        DB::transaction(function () use ($actor, $group, $ids) {
            $since = (int) ($group->last_message_id ?? 0);
            foreach ($ids as $id) {
                $this->attach($group, $id, Conversation::ROLE_MEMBER, $since);
            }
            $names = User::whereIn('id', $ids)->pluck('name')->implode(', ');
            $this->system($group, "{$actor->name} added {$names}");
        });
    }

    public function removeMember(User $actor, Conversation $group, User $target): void
    {
        $this->assertGroup($group);
        $targetRole = $group->roleOf($target);
        $actorRole = $group->roleOf($actor);

        if ($targetRole === null) {
            throw ValidationException::withMessages(['user' => 'That user is not in the group.']);
        }
        if ($targetRole === Conversation::ROLE_OWNER) {
            throw new AuthorizationException('The group owner cannot be removed.');
        }
        // Admins may remove plain members; only the owner may remove other admins.
        if ($targetRole === Conversation::ROLE_ADMIN && $actorRole !== Conversation::ROLE_OWNER) {
            throw new AuthorizationException('Only the owner can remove an admin.');
        }

        DB::transaction(function () use ($actor, $group, $target) {
            $group->members()->detach($target->id);
            $this->system($group, "{$actor->name} removed {$target->name}");
        });
        Realtime::push([$target->id], 'removed_from_conversation', ['conversation_id' => (int) $group->id]);
    }

    public function setRole(Conversation $group, User $target, string $role): void
    {
        $this->assertGroup($group);
        if (! in_array($role, [Conversation::ROLE_ADMIN, Conversation::ROLE_MEMBER], true)) {
            throw ValidationException::withMessages(['role' => 'Invalid role.']);
        }
        $current = $group->roleOf($target);
        if ($current === null) {
            throw ValidationException::withMessages(['user' => 'That user is not in the group.']);
        }
        if ($current === Conversation::ROLE_OWNER) {
            throw new AuthorizationException('Transfer ownership instead of changing the owner\'s role.');
        }
        if ($current === $role) {
            return;
        }

        DB::table('conversation_user')
            ->where('conversation_id', $group->id)->where('user_id', $target->id)
            ->update(['role' => $role, 'updated_at' => now()]);
        $this->system($group, $role === Conversation::ROLE_ADMIN
            ? "{$target->name} is now an admin"
            : "{$target->name} is no longer an admin");
    }

    public function transferOwnership(User $actor, Conversation $group, User $target): void
    {
        $this->assertGroup($group);
        if (! $group->hasMember($target) || $target->id === $actor->id) {
            throw ValidationException::withMessages(['user_id' => 'Pick another member of the group.']);
        }

        DB::transaction(function () use ($actor, $group, $target) {
            DB::table('conversation_user')->where('conversation_id', $group->id)
                ->where('user_id', $target->id)->update(['role' => Conversation::ROLE_OWNER]);
            DB::table('conversation_user')->where('conversation_id', $group->id)
                ->where('user_id', $actor->id)->update(['role' => Conversation::ROLE_ADMIN]);
            $group->forceFill(['owner_id' => $target->id])->save();
            $this->system($group, "{$actor->name} made {$target->name} the group owner");
        });
    }

    public function rename(User $actor, Conversation $group, string $name): void
    {
        $this->assertGroup($group);
        $group->forceFill(['name' => $name])->save();
        $this->system($group, "{$actor->name} renamed the group to \"{$name}\"");
    }

    public function leave(User $user, Conversation $group): void
    {
        $this->assertGroup($group);
        $role = $group->roleOf($user);
        if ($role === null) {
            throw new AuthorizationException('You are not in this group.');
        }

        if ($role === Conversation::ROLE_OWNER) {
            if (count($group->memberIds()) > 1) {
                throw ValidationException::withMessages(['group' => 'Transfer ownership to someone else before leaving.']);
            }
            $this->deleteGroup($group);

            return;
        }

        $group->members()->detach($user->id);
        $this->system($group, "{$user->name} left the group");
    }

    public function deleteGroup(Conversation $group): void
    {
        $this->assertGroup($group);
        $memberIds = $group->memberIds();
        $paths = Attachment::whereIn('message_id', $group->messages()->select('id'))->pluck('path')->all();

        $group->delete(); // cascades members, messages, attachment rows
        Storage::disk('local')->delete($paths);
        Realtime::push($memberIds, 'removed_from_conversation', ['conversation_id' => (int) $group->id]);
    }

    /* ------------------------------------------------------------------ */
    /* Messages                                                             */
    /* ------------------------------------------------------------------ */

    /** @param  array<UploadedFile>|UploadedFile|null  $files */
    public function send(User $user, Conversation $conversation, ?string $body, array|UploadedFile|null $files = null): Message
    {
        if (! $conversation->hasMember($user)) {
            throw new AuthorizationException('You are not a member of this conversation.');
        }
        if (! $conversation->isGroup()) {
            $peerId = collect($conversation->memberIds())->first(fn ($id) => $id !== (int) $user->id);
            if ($peerId === null || ! $user->isFriendWith($peerId)) {
                throw new AuthorizationException('You are no longer friends with this user.');
            }
        }

        $files = $files instanceof UploadedFile ? [$files] : array_values(array_filter($files ?? []));
        $body = $body === null ? null : trim($body);

        if (($body === null || $body === '') && ! $files) {
            throw ValidationException::withMessages(['body' => 'Message is empty.']);
        }
        if ($body !== null && mb_strlen($body) > (int) config('chat.max_message_length')) {
            throw ValidationException::withMessages(['body' => 'Message is too long.']);
        }
        if (count($files) > (int) config('chat.max_files_per_message')) {
            throw ValidationException::withMessages(['files' => 'Too many attachments.']);
        }
        foreach ($files as $file) {
            $this->assertFileAllowed($file);
        }

        $stored = [];
        try {
            $message = DB::transaction(function () use ($user, $conversation, $body, $files, &$stored) {
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $user->id,
                    'type' => $files ? Message::FILE : Message::TEXT,
                    'body' => $body === '' ? null : $body,
                ]);

                foreach ($files as $file) {
                    $attachment = $this->storeAttachment($message, $file);
                    $stored[] = $attachment->path;
                }

                $this->touchConversation($conversation, $message);
                DB::table('conversation_user')
                    ->where('conversation_id', $conversation->id)->where('user_id', $user->id)
                    ->update(['last_read_message_id' => $message->id]);

                return $message;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($stored); // don't leave orphan files behind
            throw $e;
        }

        $message->load('user', 'attachments');
        $this->scan($message);

        return $message;
    }

    public function deleteMessage(Message $message): void
    {
        $paths = $message->attachments()->pluck('path')->all();

        DB::transaction(function () use ($message) {
            $message->attachments()->delete();
            $message->forceFill(['body' => null, 'deleted_at' => now()])->save();
        });
        Storage::disk('local')->delete($paths);

        Realtime::push($message->conversation->memberIds(), 'message_deleted', [
            'conversation_id' => (int) $message->conversation_id,
            'message_id' => (int) $message->id,
        ]);
    }

    public function markRead(User $user, Conversation $conversation, int $upToMessageId): void
    {
        $membership = $conversation->membership($user);
        if (! $membership) {
            throw new AuthorizationException('You are not a member of this conversation.');
        }

        // Clamp to a real message of this conversation.
        $target = (int) ($conversation->messages()->where('id', '<=', $upToMessageId)->max('id') ?? 0);
        if ($target <= (int) $membership->last_read_message_id) {
            return;
        }

        DB::table('conversation_user')
            ->where('conversation_id', $conversation->id)->where('user_id', $user->id)
            ->update(['last_read_message_id' => $target]);

        $others = array_values(array_diff($conversation->memberIds(), [(int) $user->id]));
        Realtime::push($others, 'read', [
            'conversation_id' => (int) $conversation->id,
            'user_id' => (int) $user->id,
            'message_id' => $target,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Queries                                                              */
    /* ------------------------------------------------------------------ */

    /** @return array{messages: list<array>, has_more: bool} */
    public function history(User $user, Conversation $conversation, ?int $beforeId = null, ?int $limit = null): array
    {
        $membership = $conversation->membership($user);
        if (! $membership) {
            throw new AuthorizationException('You are not a member of this conversation.');
        }

        $limit = max(1, min($limit ?? (int) config('chat.history_page_size'), 100));
        $query = $conversation->messages()
            ->where('id', '>', (int) $membership->joined_after_message_id)
            ->with(['user:id,name,username', 'attachments'])
            ->orderByDesc('id')
            ->limit($limit + 1);

        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;

        return [
            'messages' => $rows->take($limit)->reverse()->values()->map(fn (Message $m) => $m->toPayload())->all(),
            'has_more' => $hasMore,
        ];
    }

    /** @return list<array> */
    public function summaries(User $user): array
    {
        $conversations = $user->conversations()
            ->where(fn ($q) => $q->whereNotNull('conversations.last_message_id')->orWhere('conversations.type', Conversation::GROUP))
            ->with('members:id,name,username')
            ->orderByRaw('COALESCE(conversations.last_message_at, conversations.created_at) DESC')
            ->orderByDesc('conversations.id')
            ->get();

        $lastMessages = Message::whereIn('id', $conversations->pluck('last_message_id')->filter())
            ->with(['user:id,name,username', 'attachments'])
            ->get()->keyBy('id');
        $unread = $this->unreadCounts($user, $conversations->pluck('id')->all());

        return $conversations->map(fn (Conversation $c) => $this->summaryArray(
            $user, $c, $lastMessages->get($c->last_message_id), (int) ($unread[$c->id] ?? 0)
        ))->values()->all();
    }

    public function summary(User $user, Conversation $conversation): array
    {
        $conversation->load('members:id,name,username');
        $last = $conversation->last_message_id
            ? Message::with(['user:id,name,username', 'attachments'])->find($conversation->last_message_id)
            : null;
        $unread = $this->unreadCounts($user, [$conversation->id]);

        return $this->summaryArray($user, $conversation, $last, (int) ($unread[$conversation->id] ?? 0));
    }

    /** Summary plus the member list (used by the group info dialog). */
    public function details(User $user, Conversation $conversation): array
    {
        $data = $this->summary($user, $conversation);
        $data['owner_id'] = $conversation->owner_id ? (int) $conversation->owner_id : null;
        $data['members'] = $conversation->members
            ->map(fn (User $m) => $m->toPublic() + [
                'role' => $m->pivot->role,
                'last_read_message_id' => (int) $m->pivot->last_read_message_id,
            ])->values()->all();

        return $data;
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                            */
    /* ------------------------------------------------------------------ */

    private function summaryArray(User $user, Conversation $c, ?Message $last, int $unread): array
    {
        $other = $c->isGroup() ? null : $c->members->first(fn (User $m) => $m->id !== $user->id);
        $mine = $c->members->first(fn (User $m) => $m->id === $user->id);

        return [
            'id' => (int) $c->id,
            'type' => $c->type,
            'title' => $c->isGroup() ? $c->name : ($other?->name ?? 'Unknown'),
            'other_user' => $other?->toPublic(),
            'members_count' => $c->members->count(),
            'my_role' => $mine?->pivot->role,
            'peer_read_id' => $other ? (int) $other->pivot->last_read_message_id : null,
            'last_message' => $last?->toPayload(),
            'last_message_at' => ($c->last_message_at ?? $c->created_at)?->toIso8601String(),
            'unread' => $unread,
        ];
    }

    /** @param  list<int>  $ids  @return array<int, int> conversation_id => unread count */
    private function unreadCounts(User $user, array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return DB::table('messages as m')
            ->join('conversation_user as cu', function ($join) use ($user) {
                $join->on('cu.conversation_id', '=', 'm.conversation_id')->where('cu.user_id', '=', $user->id);
            })
            ->whereIn('m.conversation_id', $ids)
            ->whereRaw('m.id > cu.last_read_message_id')
            ->where('m.type', '!=', Message::SYSTEM)
            ->whereNull('m.deleted_at')
            ->where(fn ($q) => $q->whereNull('m.user_id')->orWhere('m.user_id', '!=', $user->id))
            ->groupBy('m.conversation_id')
            ->selectRaw('m.conversation_id as cid, COUNT(*) as c')
            ->pluck('c', 'cid')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    private function attach(Conversation $c, int $userId, string $role, int $sinceMessageId): void
    {
        DB::table('conversation_user')->insert([
            'conversation_id' => $c->id,
            'user_id' => $userId,
            'role' => $role,
            'joined_after_message_id' => $sinceMessageId,
            'last_read_message_id' => $sinceMessageId,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function system(Conversation $c, string $text): Message
    {
        $message = Message::create([
            'conversation_id' => $c->id,
            'user_id' => null,
            'type' => Message::SYSTEM,
            'body' => $text,
        ]);
        $this->touchConversation($c, $message);

        return $message;
    }

    private function touchConversation(Conversation $c, Message $m): void
    {
        $c->forceFill(['last_message_id' => $m->id, 'last_message_at' => $m->created_at])->save();
    }

    private function assertGroup(Conversation $c): void
    {
        if (! $c->isGroup()) {
            throw ValidationException::withMessages(['conversation' => 'This is not a group conversation.']);
        }
    }

    /**
     * Keep only ids that are accepted friends of $actor; reject the request if
     * anyone else is included (prevents adding arbitrary users to groups).
     *
     * @param  array<int|string>  $ids  @return list<int>
     */
    private function friendsOnly(User $actor, array $ids, string $field): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $ids = array_values(array_diff($ids, [(int) $actor->id]));
        $notFriends = array_diff($ids, $actor->friendIds());

        if ($notFriends) {
            throw ValidationException::withMessages([$field => 'You can only add people from your friend list.']);
        }

        return $ids;
    }

    private function assertFileAllowed(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['files' => 'Upload failed. The file may be too large for the server.']);
        }
        if ($file->getSize() > (int) config('chat.max_upload_kb') * 1024) {
            throw ValidationException::withMessages(['files' => 'File is larger than '.(int) config('chat.max_upload_kb') / 1024 .' MB.']);
        }
        $ext = $this->cleanExtension($file);
        if ($ext !== '' && in_array($ext, config('chat.blocked_extensions'), true)) {
            throw ValidationException::withMessages(['files' => "Files of type .{$ext} are not allowed."]);
        }
    }

    private function cleanExtension(UploadedFile $file): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $file->getClientOriginalExtension()));
    }

    private function storeAttachment(Message $message, UploadedFile $file): Attachment
    {
        $ext = $this->cleanExtension($file);
        $name = (string) Str::uuid().($ext !== '' ? ".{$ext}" : '');
        $path = $file->storeAs('chat/'.now()->format('Y/m'), $name, 'local');

        // Trust the content sniff, not the client-supplied type.
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $original = mb_substr(trim(str_replace(["\0", '/', '\\'], '', $file->getClientOriginalName())), 0, 200);

        return $message->attachments()->create([
            'path' => $path,
            'original_name' => $original !== '' ? $original : 'file',
            'mime' => $mime,
            'size' => $file->getSize(),
            'is_image' => in_array($mime, config('chat.image_mimes'), true),
        ]);
    }

    private function scan(Message $message): void
    {
        if (! config('chat.spam.enabled') || $message->type !== Message::TEXT || ! $message->body) {
            return;
        }

        try {
            $verdict = $this->spam->classify($message);
            if ($verdict) {
                $message->forceFill([
                    'spam_label' => $verdict['label'],
                    'spam_score' => $verdict['score'],
                    'scanned_at' => now(),
                ])->save();
            }
        } catch (Throwable $e) {
            // A detector outage must never block messaging.
            Log::warning('Spam detector failed: '.$e->getMessage());
        }
    }
}
