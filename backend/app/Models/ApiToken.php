<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiToken extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['expires_at' => 'datetime', 'last_used_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
