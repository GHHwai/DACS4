<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Note: is_admin is deliberately NOT mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'bio',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class)
            ->withPivot(['role', 'joined_after_message_id', 'last_read_message_id', 'joined_at'])
            ->withTimestamps();
    }

    /** @return list<int> ids of accepted friends */
    public function friendIds(): array
    {
        return Friendship::query()
            ->where('status', Friendship::ACCEPTED)
            ->where(fn ($q) => $q->where('requester_id', $this->id)->orWhere('addressee_id', $this->id))
            ->get(['requester_id', 'addressee_id'])
            ->map(fn (Friendship $f) => $f->other((int) $this->id))
            ->values()
            ->all();
    }

    public function isFriendWith(int $otherId): bool
    {
        return Friendship::query()
            ->where('pair_key', Friendship::keyFor((int) $this->id, $otherId))
            ->where('status', Friendship::ACCEPTED)
            ->exists();
    }

    /** Safe-to-share representation (never includes email). */
    public function toPublic(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'username' => $this->username,
        ];
    }
}
