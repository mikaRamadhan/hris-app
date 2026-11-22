<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['check_in_photo', 'check_out_photo'];
    protected $appends = ['has_check_in_photo', 'has_check_out_photo', 'presence_category', 'late_minutes', 'early_minutes', 'work_minutes'];
    protected $casts = [
        'attendance_date' => 'date:Y-m-d',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'check_in_location_verified' => 'boolean', 'check_in_face_verified' => 'boolean', 'check_in_liveness_passed' => 'boolean',
        'check_out_location_verified' => 'boolean', 'check_out_face_verified' => 'boolean', 'check_out_liveness_passed' => 'boolean',
    ];

    // --- Waktu kantor (WIB) ---
    public static function tz(): string
    {
        return (string) config('hris.timezone', 'Asia/Jakarta');
    }

    public static function bizNow(): Carbon
    {
        return Carbon::now(static::tz());
    }

    /** Tanggal hari ini menurut zona waktu kantor (jam 00:00). */
    public static function bizToday(): Carbon
    {
        return static::bizNow()->startOfDay();
    }

    public static function isWorkDay($date): bool
    {
        return in_array(Carbon::parse($date)->dayOfWeekIso, (array) config('hris.work_days', [1, 2, 3, 4, 5]), true);
    }

    /** on_time | tolerance | late untuk waktu check-in (sudah dalam zona waktu kantor). */
    public static function inStatusFor(Carbon $t): string
    {
        $limit = fn (string $k, string $d) => $t->copy()->setTimeFromTimeString(config("hris.$k", $d))->endOfMinute();

        return $t->lte($limit('check_in_on_time', '09:00')) ? 'on_time'
            : ($t->lte($limit('check_in_tolerance', '09:30')) ? 'tolerance' : 'late');
    }

    /** normal | early_leave untuk waktu check-out (sudah dalam zona waktu kantor). */
    public static function outStatusFor(Carbon $t): string
    {
        return $t->gte($t->copy()->setTimeFromTimeString(config('hris.check_out_from', '17:00'))->startOfMinute()) ? 'normal' : 'early_leave';
    }

    private function bizTime($value): ?Carbon
    {
        return $value ? $value->copy()->setTimezone(static::tz()) : null;
    }

    // --- Atribut turunan. Status dihitung ulang dari jam sebenarnya sehingga data lama (yang terlanjur memakai UTC) ikut benar. ---
    public function getCheckInStatusAttribute($value)
    {
        $t = $this->bizTime($this->check_in_at);

        return $t ? static::inStatusFor($t) : $value;
    }

    public function getCheckOutStatusAttribute($value)
    {
        $t = $this->bizTime($this->check_out_at);

        return $t ? static::outStatusFor($t) : $value;
    }

    /** Menit keterlambatan dihitung dari batas tepat waktu (mis. 09:00); 0 jika tidak terlambat. */
    public function getLateMinutesAttribute(): int
    {
        $t = $this->bizTime($this->check_in_at);
        if (!$t) {
            return 0;
        }
        $limit = $t->copy()->setTimeFromTimeString(config('hris.check_in_on_time', '09:00'))->startOfMinute();

        return static::inStatusFor($t) === 'on_time' ? 0 : max(0, (int) floor(($t->timestamp - $limit->timestamp) / 60));
    }

    /** Menit pulang lebih awal dari jam pulang (mis. 17:00); 0 jika tidak pulang awal. */
    public function getEarlyMinutesAttribute(): int
    {
        $t = $this->bizTime($this->check_out_at);
        if (!$t || static::outStatusFor($t) === 'normal') {
            return 0;
        }
        $limit = $t->copy()->setTimeFromTimeString(config('hris.check_out_from', '17:00'))->startOfMinute();

        return max(0, (int) ceil(($limit->timestamp - $t->timestamp) / 60));
    }

    /** Lama bekerja (menit) antara check-in dan check-out. */
    public function getWorkMinutesAttribute(): ?int
    {
        if (!$this->check_in_at || !$this->check_out_at) {
            return null;
        }

        return max(0, (int) floor(($this->check_out_at->timestamp - $this->check_in_at->timestamp) / 60));
    }

    public function getHasCheckInPhotoAttribute(): bool
    {
        return filled($this->attributes['check_in_photo'] ?? null);
    }

    public function getHasCheckOutPhotoAttribute(): bool
    {
        return filled($this->attributes['check_out_photo'] ?? null);
    }

    /**
     * masuk  = sudah check-in (termasuk telat / pulang awal)
     * izin / cuti = ditandai HRD
     * libur  = bukan hari kerja dan tidak ada catatan
     * alpa   = hari kerja yang sudah lewat tanpa check-in, izin, atau cuti (tanpa keterangan)
     * belum  = hari kerja hari ini yang belum check-in
     */
    public function getPresenceCategoryAttribute(): string
    {
        if (in_array($this->attendance_status, ['izin', 'cuti'], true)) {
            return $this->attendance_status;
        }
        if ($this->check_in_at) {
            return 'masuk';
        }
        $date = $this->attendance_date;
        if (!$date) {
            return 'belum';
        }
        if (!static::isWorkDay($date)) {
            return 'libur';
        }

        return $date->toDateString() < static::bizToday()->toDateString() ? 'alpa' : 'belum';
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function location()
    {
        return $this->belongsTo(AttendanceLocation::class, 'attendance_location_id');
    }
}
