<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];
    protected $casts = ['join_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'birth_date' => 'date:Y-m-d', 'is_active' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function department()
    {
        return $this->belongsTo(OrganizationalUnit::class, 'department_id');
    }

    public function position()
    {
        return $this->belongsTo(OrganizationalUnit::class, 'position_id');
    }

    public function status()
    {
        return $this->belongsTo(EmployeeStatus::class, 'employee_status_id');
    }

    public function faceProfile()
    {
        return $this->hasOne(EmployeeFaceProfile::class);
    }

    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public static function nextNumber(): string
    {
        $prefix = 'EMP-' . now()->format('Y') . '-';
        $last = static::withTrashed()->where('employee_number', 'like', $prefix . '%')->orderByDesc('employee_number')->value('employee_number');
        $n = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
