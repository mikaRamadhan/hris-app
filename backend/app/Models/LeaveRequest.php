<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pengajuan cuti karyawan. Setelah disetujui HRD, hari kerjanya ditandai di absensi (cuti tahunan = "cuti", lainnya = "izin"). */
class LeaveRequest extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['attachment_path'];
    protected $appends = ['type_label', 'has_attachment'];
    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'reviewed_at' => 'datetime',
        'days' => 'integer',
    ];

    /** Jenis cuti standar. deducts = memotong jatah cuti tahunan. */
    public const TYPES = [
        'tahunan' => ['label' => 'Cuti Tahunan', 'deducts' => true, 'note' => 'Memotong jatah cuti tahunan'],
        'sakit' => ['label' => 'Cuti Sakit', 'deducts' => false, 'note' => 'Tanpa batas kuota. Lampirkan surat dokter bila ada'],
        'penting' => ['label' => 'Cuti Alasan Penting', 'deducts' => false, 'note' => 'Mis. pernikahan atau keluarga meninggal. Tidak memotong jatah tahunan'],
    ];

    public static function typeList(): array
    {
        $out = [];
        foreach (self::TYPES as $value => $t) {
            $out[] = ['value' => $value] + $t;
        }

        return $out;
    }

    /** Membuat tabel otomatis bila "php artisan migrate" belum dijalankan. */
    public static function tableReady(): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }
        try {
            if (!Schema::hasTable('leave_requests')) {
                Schema::create('leave_requests', function (Blueprint $t) {
                    $t->id();
                    $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
                    $t->string('type', 20);
                    $t->date('start_date');
                    $t->date('end_date');
                    $t->unsignedSmallInteger('days');
                    $t->text('reason');
                    $t->string('attachment_path')->nullable();
                    $t->string('attachment_name')->nullable();
                    $t->string('status', 15)->default('pending'); // pending | approved | rejected | cancelled
                    $t->unsignedBigInteger('reviewed_by')->nullable();
                    $t->dateTime('reviewed_at')->nullable();
                    $t->text('review_note')->nullable();
                    $t->timestamps();
                    $t->index(['employee_id', 'status']);
                });
            }

            return $ready = Schema::hasTable('leave_requests');
        } catch (\Throwable $e) {
            return $ready = false;
        }
    }

    /** Daftar tanggal hari kerja (Y-m-d) dari $from sampai $to, sesuai config hris.work_days. */
    public static function workDates($from, $to): array
    {
        $workDays = (array) config('hris.work_days', [1, 2, 3, 4, 5]);
        $out = [];
        for ($d = Carbon::parse($from)->startOfDay(); $d->lte(Carbon::parse($to)->startOfDay()); $d->addDay()) {
            if (in_array($d->dayOfWeekIso, $workDays, true)) {
                $out[] = $d->toDateString();
            }
        }

        return $out;
    }

    /** Jumlah hari cuti tahunan yang masih menunggu persetujuan pada tahun tertentu. */
    public static function pendingAnnualDays(int $employeeId, int $year): int
    {
        if (!static::tableReady()) {
            return 0;
        }

        return (int) static::where('employee_id', $employeeId)->where('type', 'tahunan')->where('status', 'pending')
            ->whereYear('start_date', $year)->sum('days');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type]['label'] ?? (string) $this->type;
    }

    public function getHasAttachmentAttribute(): bool
    {
        return filled($this->attributes['attachment_path'] ?? null);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
