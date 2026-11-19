<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceAttempt extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['location_ok' => 'boolean', 'face_ok' => 'boolean', 'liveness_ok' => 'boolean', 'success' => 'boolean'];

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
