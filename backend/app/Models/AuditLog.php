<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['details' => 'array'];

    public static function record(?User $user, string $action, ?Model $subject = null, array $details = []): void
    {
        static::create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'details' => $details ?: null,
            'ip' => request()?->ip(),
        ]);
    }
}
