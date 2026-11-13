<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name): User
    {
        return User::create([
            'name' => ucfirst($name), 'username' => $name,
            'email' => "{$name}@example.com", 'password' => 'Password1',
        ]);
    }

    private function befriend(User $a, User $b): void
    {
        Friendship::create([
            'requester_id' => $a->id, 'addressee_id' => $b->id,
            'pair_key' => Friendship::keyFor($a->id, $b->id),
            'status' => Friendship::ACCEPTED, 'accepted_at' => now(),
        ]);
    }

    public function test_guests_are_redirected_and_api_returns_401(): void
    {
        $this->get('/chat')->assertRedirect('/login');
        $this->getJson('/api/bootstrap')->assertUnauthorized();
    }

    public function test_register_login_and_logout(): void
    {
        $this->post('/register', [
            'name' => 'Ann', 'username' => 'Ann_1', 'email' => 'ann@example.com',
            'password' => 'Password1', 'password_confirmation' => 'Password1',
        ])->assertRedirect('/chat');
        $this->assertAuthenticated();
        $this->assertSame('ann_1', User::first()->username);          // normalised to lower case
        $this->assertNotSame('Password1', User::first()->password);   // hashed

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();

        $this->post('/login', ['login' => 'ann_1', 'password' => 'nope'])->assertSessionHasErrors('login');
        $this->post('/login', ['login' => 'ann@example.com', 'password' => 'Password1'])->assertRedirect('/chat');
    }

    public function test_weak_passwords_and_duplicate_usernames_are_rejected(): void
    {
        $this->user('taken');
        $base = ['name' => 'X', 'email' => 'x@example.com', 'password_confirmation' => 'short'];
        $this->post('/register', $base + ['username' => 'xx1', 'password' => 'short'])->assertSessionHasErrors('password');
        $this->post('/register', ['username' => 'taken', 'password' => 'Password1', 'password_confirmation' => 'Password1'] + $base)
            ->assertSessionHasErrors('username');
    }

    public function test_is_admin_is_not_mass_assignable(): void
    {
        $u = User::create(['name' => 'A', 'username' => 'aaa', 'email' => 'a@a.com', 'password' => 'Password1', 'is_admin' => true]);
        $this->assertFalse($u->fresh()->is_admin);
    }

    public function test_friend_request_flow_and_authorization(): void
    {
        [$a, $b, $c] = [$this->user('alice'), $this->user('bob'), $this->user('carol')];

        $id = $this->actingAs($a)->postJson('/api/friends/requests', ['user_id' => $b->id])->assertCreated()->json('friendship_id');
        $this->actingAs($a)->postJson("/api/friends/requests/{$id}/accept")->assertForbidden();   // requester can't self-accept
        $this->actingAs($c)->postJson("/api/friends/requests/{$id}/accept")->assertForbidden();   // third party can't either
        $this->actingAs($c)->deleteJson("/api/friends/requests/{$id}")->assertForbidden();
        $this->actingAs($b)->postJson("/api/friends/requests/{$id}/accept")->assertOk();
        $this->assertTrue($a->isFriendWith($b->id));

        $this->actingAs($a)->postJson('/api/friends/requests', ['user_id' => $b->id])->assertStatus(422);  // already friends
        $this->actingAs($a)->postJson('/api/friends/requests', ['user_id' => $a->id])->assertStatus(422);  // self
    }

    public function test_direct_chat_requires_friendship_and_membership_is_enforced(): void
    {
        [$a, $b, $c] = [$this->user('alice'), $this->user('bob'), $this->user('carol')];
        $chat = app(ChatService::class);

        $this->actingAs($a)->postJson('/api/conversations/direct', ['user_id' => $b->id])->assertForbidden();

        $this->befriend($a, $b);
        $cid = $this->actingAs($a)->postJson('/api/conversations/direct', ['user_id' => $b->id])->assertOk()->json('conversation.id');
        $again = $this->actingAs($b)->postJson('/api/conversations/direct', ['user_id' => $a->id])->json('conversation.id');
        $this->assertSame($cid, $again, 'a pair can only ever have one direct conversation');

        $this->actingAs($a)->postJson("/api/conversations/{$cid}/messages", ['body' => 'hi'])->assertCreated();
        $this->actingAs($c)->getJson("/api/conversations/{$cid}/messages")->assertForbidden();
        $this->actingAs($c)->postJson("/api/conversations/{$cid}/messages", ['body' => 'x'])->assertForbidden();

        // Unfriending stops further messages.
        Friendship::query()->delete();
        $this->expectException(AuthorizationException::class);
        $chat->send($a, Conversation::find($cid), 'still there?');
    }

    public function test_group_roles(): void
    {
        [$owner, $admin, $member, $outsider] = [$this->user('owner'), $this->user('admin'), $this->user('member'), $this->user('outsider')];
        foreach ([$admin, $member] as $f) {
            $this->befriend($owner, $f);
        }
        $gid = $this->actingAs($owner)->postJson('/api/groups', ['name' => 'Team', 'member_ids' => [$admin->id, $member->id]])
            ->assertCreated()->json('conversation.id');

        $this->actingAs($owner)->patchJson("/api/groups/{$gid}/members/{$admin->id}", ['role' => 'admin'])->assertOk();
        $this->actingAs($member)->patchJson("/api/groups/{$gid}", ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($admin)->patchJson("/api/groups/{$gid}", ['name' => 'Renamed'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/groups/{$gid}/members/{$member->id}", ['role' => 'admin'])->assertForbidden(); // only owner
        $this->actingAs($admin)->deleteJson("/api/groups/{$gid}/members/{$owner->id}")->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/groups/{$gid}")->assertForbidden();
        $this->actingAs($owner)->postJson("/api/groups/{$gid}/leave")->assertStatus(422);        // must transfer first

        // Only friends can be added.
        $this->actingAs($owner)->postJson("/api/groups/{$gid}/members", ['user_ids' => [$outsider->id]])->assertStatus(422);

        // A late joiner must not see earlier history.
        $late = $this->user('late');
        $this->befriend($owner, $late);
        $this->actingAs($owner)->postJson("/api/conversations/{$gid}/messages", ['body' => 'secret before join'])->assertCreated();
        $this->actingAs($owner)->postJson("/api/groups/{$gid}/members", ['user_ids' => [$late->id]])->assertOk();
        $bodies = collect($this->actingAs($late)->getJson("/api/conversations/{$gid}/messages")->json('messages'))->pluck('body');
        $this->assertFalse($bodies->contains('secret before join'));

        $this->actingAs($admin)->deleteJson("/api/groups/{$gid}/members/{$member->id}")->assertOk();
        $this->actingAs($member)->getJson("/api/conversations/{$gid}/messages")->assertForbidden();
        $this->actingAs($owner)->deleteJson("/api/groups/{$gid}")->assertOk();
        $this->assertNull(Conversation::find($gid));
    }

    public function test_attachments_are_private_validated_and_cleaned_up(): void
    {
        Storage::fake('local');
        [$a, $b, $c] = [$this->user('alice'), $this->user('bob'), $this->user('carol')];
        $this->befriend($a, $b);
        $cid = $this->actingAs($a)->postJson('/api/conversations/direct', ['user_id' => $b->id])->json('conversation.id');

        $this->actingAs($a)->postJson("/api/conversations/{$cid}/messages", [
            'files' => [UploadedFile::fake()->create('shell.php', 1)],
        ])->assertStatus(422);

        $res = $this->actingAs($a)->post("/api/conversations/{$cid}/messages", [
            'body' => 'doc', 'files' => [UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated();
        $att = $res->json('message.attachments.0');
        $this->assertSame('report.pdf', $att['name']);
        Storage::disk('local')->assertExists(\App\Models\Attachment::first()->path);

        $this->actingAs($b)->get($att['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($c)->get($att['url'])->assertForbidden();
        $this->app['auth']->forgetGuards(); // drop the acting-as user: now a guest
        $this->get($att['url'])->assertRedirect('/login');

        $path = \App\Models\Attachment::first()->path;
        $mid = $res->json('message.id');
        $this->actingAs($b)->deleteJson("/api/messages/{$mid}")->assertForbidden();   // only the author can recall
        $this->actingAs($a)->deleteJson("/api/messages/{$mid}")->assertOk();
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, \App\Models\Attachment::count());
    }

    public function test_socket_tokens_are_hashed_and_expire(): void
    {
        $u = $this->user('alice');
        $plain = \App\Models\ChatToken::issue($u, 60);
        $this->assertDatabaseMissing('chat_tokens', ['token_hash' => $plain]);
        $this->assertSame($u->id, \App\Models\ChatToken::resolve($plain)?->id);
        $this->assertNull(\App\Models\ChatToken::resolve('garbage'));

        $this->travel(2)->minutes();
        $this->assertNull(\App\Models\ChatToken::resolve($plain));
    }
}
