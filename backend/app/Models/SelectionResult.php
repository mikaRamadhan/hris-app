<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SelectionResult extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['is_announced' => 'boolean'];

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
