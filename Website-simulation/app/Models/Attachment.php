<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $fillable = ['message_id', 'path', 'original_name', 'mime', 'size', 'is_image'];

    protected function casts(): array
    {
        return ['is_image' => 'boolean', 'size' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function toPayload(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->original_name,
            'mime' => $this->mime,
            'size' => (int) $this->size,
            'is_image' => (bool) $this->is_image,
            // Served through an authorized route; files are never publicly reachable.
            'url' => route('attachments.show', ['attachment' => $this->id], false),
        ];
    }
}
