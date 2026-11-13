<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    public const TEXT = 'text';
    public const FILE = 'file';
    public const SYSTEM = 'system';

    protected $fillable = [
        'conversation_id', 'user_id', 'type', 'body',
        'spam_label', 'spam_score', 'scanned_at', 'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'spam_score' => 'float',
            'scanned_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** JSON shape shared by REST, WebSocket and TCP clients. */
    public function toPayload(): array
    {
        $deleted = $this->deleted_at !== null;

        return [
            'id' => (int) $this->id,
            'conversation_id' => (int) $this->conversation_id,
            'type' => $this->type,
            'user' => $this->user?->toPublic(),
            'body' => $deleted ? null : $this->body,
            'deleted' => $deleted,
            'attachments' => $deleted ? [] : $this->attachments->map(fn (Attachment $a) => $a->toPayload())->all(),
            'created_at' => $this->created_at?->toIso8601String(),
            'spam_label' => $this->spam_label,
        ];
    }
}
