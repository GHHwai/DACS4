<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Conversation extends Model
{
    public const DIRECT = 'direct';
    public const GROUP = 'group';

    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    protected $fillable = ['type', 'name', 'owner_id', 'direct_key', 'last_message_id', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public static function directKey(int $a, int $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'joined_after_message_id', 'last_read_message_id', 'joined_at'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isGroup(): bool
    {
        return $this->type === self::GROUP;
    }

    public function membership(User|int $user): ?object
    {
        return DB::table('conversation_user')
            ->where('conversation_id', $this->id)
            ->where('user_id', $user instanceof User ? $user->id : $user)
            ->first();
    }

    public function roleOf(User|int $user): ?string
    {
        return $this->membership($user)?->role;
    }

    public function hasMember(User|int $user): bool
    {
        return $this->membership($user) !== null;
    }

    /** @return list<int> */
    public function memberIds(): array
    {
        return DB::table('conversation_user')
            ->where('conversation_id', $this->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
