<?php

namespace App\Http\Controllers;

use App\Models\Friendship;
use App\Models\User;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FriendController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->lists($request->user()));
    }

    /** @return array{friends: list<array>, incoming: list<array>, outgoing: list<array>} */
    public function lists(User $user): array
    {
        $rows = Friendship::query()
            ->where(fn ($q) => $q->where('requester_id', $user->id)->orWhere('addressee_id', $user->id))
            ->with('requester:id,name,username', 'addressee:id,name,username')
            ->orderByDesc('updated_at')
            ->get();

        $friends = [];
        $incoming = [];
        $outgoing = [];
        foreach ($rows as $f) {
            $other = (int) $f->requester_id === (int) $user->id ? $f->addressee : $f->requester;
            $entry = $other->toPublic() + ['friendship_id' => (int) $f->id];

            if ($f->status === Friendship::ACCEPTED) {
                $friends[] = $entry;
            } elseif ((int) $f->addressee_id === (int) $user->id) {
                $incoming[] = $entry;
            } else {
                $outgoing[] = $entry;
            }
        }
        usort($friends, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return compact('friends', 'incoming', 'outgoing');
    }

    /** Find people by username prefix or exact e-mail (no partial e-mail matching, to limit enumeration). */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:50']]);
        $me = $request->user();
        $q = trim($data['q']);
        $prefix = Str::lower(str_replace(['%', '\\'], '', ltrim($q, '@')));

        $users = User::query()
            ->where('id', '!=', $me->id)
            ->where(fn ($w) => $w->where('username', 'like', $prefix.'%')->orWhere('email', $q))
            ->orderBy('username')
            ->limit(10)
            ->get(['id', 'name', 'username']);

        $keys = $users->map(fn ($u) => Friendship::keyFor((int) $me->id, (int) $u->id));
        $relations = Friendship::whereIn('pair_key', $keys)->get()->keyBy('pair_key');

        return response()->json(['users' => $users->map(function (User $u) use ($me, $relations) {
            $f = $relations->get(Friendship::keyFor((int) $me->id, (int) $u->id));
            $status = match (true) {
                $f === null => 'none',
                $f->status === Friendship::ACCEPTED => 'friend',
                (int) $f->requester_id === (int) $me->id => 'pending_out',
                default => 'pending_in',
            };

            return $u->toPublic() + ['relation' => $status, 'friendship_id' => $f?->id];
        })->values()]);
    }

    public function request(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $me = $request->user();
        $target = User::findOrFail($data['user_id']);

        if ($target->id === $me->id) {
            throw ValidationException::withMessages(['user_id' => 'You cannot add yourself.']);
        }

        $existing = Friendship::where('pair_key', Friendship::keyFor((int) $me->id, (int) $target->id))->first();

        if ($existing) {
            if ($existing->status === Friendship::ACCEPTED) {
                throw ValidationException::withMessages(['user_id' => 'You are already friends.']);
            }
            if ((int) $existing->requester_id === (int) $me->id) {
                throw ValidationException::withMessages(['user_id' => 'Friend request already sent.']);
            }

            // They already asked us: treat this as accepting.
            return $this->acceptFriendship($existing, $me);
        }

        $friendship = Friendship::create([
            'requester_id' => $me->id,
            'addressee_id' => $target->id,
            'pair_key' => Friendship::keyFor((int) $me->id, (int) $target->id),
            'status' => Friendship::PENDING,
        ]);
        Realtime::push([$target->id], 'friend_request', ['from' => $me->toPublic(), 'friendship_id' => (int) $friendship->id]);

        return response()->json(['status' => 'pending', 'friendship_id' => (int) $friendship->id], 201);
    }

    public function accept(Request $request, Friendship $friendship): JsonResponse
    {
        Gate::authorize('accept', $friendship);

        return $this->acceptFriendship($friendship, $request->user());
    }

    /** Decline an incoming request or cancel one you sent. */
    public function cancel(Request $request, Friendship $friendship): JsonResponse
    {
        Gate::authorize('cancel', $friendship);
        $otherId = $friendship->other((int) $request->user()->id);
        $friendship->delete();
        Realtime::push([$otherId], 'friend_request_cancelled', ['friendship_id' => (int) $friendship->id]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $me = $request->user();
        $friendship = Friendship::where('pair_key', Friendship::keyFor((int) $me->id, (int) $user->id))
            ->where('status', Friendship::ACCEPTED)
            ->firstOrFail();

        $friendship->delete();
        Realtime::push([$user->id], 'friend_removed', ['user_id' => (int) $me->id]);

        return response()->json(['ok' => true]);
    }

    private function acceptFriendship(Friendship $friendship, User $me): JsonResponse
    {
        $friendship->forceFill(['status' => Friendship::ACCEPTED, 'accepted_at' => now()])->save();
        Realtime::push([$friendship->other((int) $me->id)], 'friend_accepted', ['by' => $me->toPublic()]);

        return response()->json(['status' => 'accepted', 'friendship_id' => (int) $friendship->id]);
    }
}
