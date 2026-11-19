<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceLocation extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['latitude' => 'float', 'longitude' => 'float', 'radius_meters' => 'integer', 'is_active' => 'boolean'];
}
