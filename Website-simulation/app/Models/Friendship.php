<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Friendship extends Model
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';

    protected $fillable = ['requester_id', 'addressee_id', 'pair_key', 'status', 'accepted_at'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public static function keyFor(int $a, int $b): string
    {
        return min($a, $b).'-'.max($a, $b);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function addressee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'addressee_id');
    }

    public function other(int $userId): int
    {
        return (int) $this->requester_id === $userId ? (int) $this->addressee_id : (int) $this->requester_id;
    }

    public function involves(int $userId): bool
    {
        return (int) $this->requester_id === $userId || (int) $this->addressee_id === $userId;
    }
}
