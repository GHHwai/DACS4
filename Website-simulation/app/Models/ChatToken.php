<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ChatToken extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Issue a token; only the hash is stored, the plaintext is returned once. */
    public static function issue(User $user, ?int $ttlSeconds = null): string
    {
        static::where('user_id', $user->id)->where('expires_at', '<', now())->delete();

        $plain = Str::random(48);
        static::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addSeconds($ttlSeconds ?? (int) config('chat.token_ttl')),
        ]);

        return $plain;
    }

    public static function resolve(string $plain): ?User
    {
        if ($plain === '') {
            return null;
        }

        $token = static::with('user')
            ->where('token_hash', hash('sha256', $plain))
            ->where('expires_at', '>', now())
            ->first();

        return $token?->user;
    }
}
