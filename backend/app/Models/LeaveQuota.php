<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Jatah cuti tahunan per karyawan. Cuti terpakai dihitung dari catatan absensi berstatus "cuti". */
class LeaveQuota extends Model
{
    protected $guarded = ['id'];

    public static function defaultDays(): int
    {
        return (int) config('hris.leave_default_days', 12);
    }

    /** False jika migration leave_quotas belum dijalankan (php artisan migrate). */
    public static function tableReady(): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }
        try {
            if (!Schema::hasTable('leave_quotas')) {
                // Dibuat otomatis agar halaman Jatah Cuti langsung bekerja walau "php artisan migrate" belum dijalankan.
                Schema::create('leave_quotas', function (Blueprint $t) {
                    $t->id();
                    $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
                    $t->unsignedSmallInteger('year');
                    $t->unsignedSmallInteger('total_days')->default(12);
                    $t->timestamps();
                    $t->unique(['employee_id', 'year']);
                });
            }

            return $ready = Schema::hasTable('leave_quotas');
        } catch (\Throwable $e) {
            return $ready = false;
        }
    }

    public static function totalFor(int $employeeId, int $year): int
    {
        if (!static::tableReady()) {
            return static::defaultDays();
        }

        return (int) (static::where('employee_id', $employeeId)->where('year', $year)->value('total_days') ?? static::defaultDays());
    }

    public static function usedFor(int $employeeId, int $year, ?int $exceptRecordId = null): int
    {
        return AttendanceRecord::where('employee_id', $employeeId)
            ->where('attendance_status', 'cuti')
            ->whereYear('attendance_date', $year)
            ->when($exceptRecordId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->count();
    }
}
