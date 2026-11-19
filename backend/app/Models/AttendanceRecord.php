<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    protected $guarded = ['id'];
    protected $casts = [
        'attendance_date' => 'date:Y-m-d',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'check_in_location_verified' => 'boolean', 'check_in_face_verified' => 'boolean', 'check_in_liveness_passed' => 'boolean',
        'check_out_location_verified' => 'boolean', 'check_out_face_verified' => 'boolean', 'check_out_liveness_passed' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function location()
    {
        return $this->belongsTo(AttendanceLocation::class, 'attendance_location_id');
    }
}
