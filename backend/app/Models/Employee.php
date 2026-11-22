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

    /**
     * Akun HRD tidak dibuat lewat konversi pelamar, jadi data karyawannya dibuat otomatis (sekali)
     * agar HRD juga punya jabatan/departemen, registrasi wajah, dan absensi seperti karyawan.
     */
    public static function ensureForUser(User $user): self
    {
        if ($e = static::where('user_id', $user->id)->first()) {
            return $e;
        }

        return static::create([
            'user_id' => $user->id, 'employee_number' => static::nextNumber(), 'full_name' => $user->name, 'email' => $user->email,
            'department_id' => OrganizationalUnit::where('type', 'department')->where('name', 'Human Resources')->value('id'),
            'position_id' => OrganizationalUnit::where('type', 'position')->where('name', 'Staf HR')->value('id'),
            'employee_status_id' => EmployeeStatus::where('name', 'Tetap')->value('id'),
            'join_date' => today()->toDateString(), 'is_active' => true,
        ]);
    }

    public static function nextNumber(): string
    {
        $prefix = 'EMP-' . now()->format('Y') . '-';
        $last = static::withTrashed()->where('employee_number', 'like', $prefix . '%')->orderByDesc('employee_number')->value('employee_number');
        $n = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
