<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveQuota;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** HRD: jatah cuti per karyawan, pengajuan cuti, persetujuan. Cuti terpakai = hari berstatus "cuti" di absensi. */
class LeaveController extends Controller
{
    private const MIGRATE_MSG = 'Tabel jatah cuti tidak dapat dibuat otomatis. Jalankan "php artisan migrate" di folder backend, lalu muat ulang halaman ini.';

    public function index(Request $r)
    {
        $r->validate(['year' => 'nullable|integer|min:2000|max:2100']);
        $year = (int) ($r->year ?: AttendanceRecord::bizToday()->year);
        $ready = LeaveQuota::tableReady() && LeaveRequest::tableReady();

        $employees = Employee::with('department:id,name')->where('is_active', true)
            ->when($r->search, function ($q, $v) {
                $like = '%' . mb_strtolower(trim($v)) . '%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(full_name) LIKE ?', [$like])->orWhereRaw('LOWER(employee_number) LIKE ?', [$like]));
            })
            ->when($r->department_id, fn ($q, $v) => $q->where('department_id', $v))
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_number', 'department_id']);
        $ids = $employees->pluck('id');

        $quotas = $ready ? LeaveQuota::where('year', $year)->pluck('total_days', 'employee_id') : collect();
        $leaveDays = AttendanceRecord::where('attendance_status', 'cuti')->whereYear('attendance_date', $year)
            ->whereIn('employee_id', $ids)->orderBy('attendance_date')->get(['employee_id', 'attendance_date'])->groupBy('employee_id');
        $requests = $ready
            ? LeaveRequest::with('reviewer:id,name')->whereIn('employee_id', $ids)->whereYear('start_date', $year)->latest('id')->get()->groupBy('employee_id')
            : collect();

        $default = LeaveQuota::defaultDays();
        $rows = $employees->map(function ($e) use ($quotas, $leaveDays, $requests, $default) {
            $total = (int) ($quotas[$e->id] ?? $default);
            $dates = $leaveDays->get($e->id, collect())->map(fn ($a) => $a->attendance_date->toDateString())->values();
            $used = $dates->count();
            $remaining = $total - $used;
            $reqs = $requests->get($e->id, collect())->values();
            $pending = $reqs->where('status', 'pending');

            return [
                'employee' => ['id' => $e->id, 'full_name' => $e->full_name, 'employee_number' => $e->employee_number, 'department' => $e->department?->name],
                'total_days' => $total,
                'used' => $used,
                'remaining' => $remaining,
                'pending_days' => (int) $pending->where('type', 'tahunan')->sum('days'),
                'pending_count' => $pending->count(),
                'percent_used' => $total > 0 ? min(100, (int) round($used / $total * 100)) : ($used > 0 ? 100 : 0),
                'state' => $remaining <= 0 ? 'habis' : ($remaining <= max(2, (int) ceil($total * 0.25)) ? 'menipis' : 'aman'),
                'dates' => $dates->take(31)->all(),
                'custom' => isset($quotas[$e->id]),
                'requests' => $reqs->take(30)->values(),
            ];
        })->values();

        return [
            'year' => $year,
            'default_days' => $default,
            'editable' => $ready,
            'needs_migration' => !$ready,
            'message' => $ready ? null : self::MIGRATE_MSG,
            'types' => LeaveRequest::typeList(),
            'data' => $rows,
            'summary' => [
                'employees' => $rows->count(),
                'pending_requests' => $rows->sum('pending_count'),
                'low' => $rows->where('state', 'menipis')->count(),
                'exhausted' => $rows->where('state', 'habis')->count(),
            ],
        ];
    }

    public function update(Request $r, int $employeeId)
    {
        abort_unless(LeaveQuota::tableReady(), 503, self::MIGRATE_MSG);
        $d = $r->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'total_days' => 'required|integer|min:0|max:365',
        ]);
        $employee = Employee::findOrFail($employeeId);
        LeaveQuota::updateOrCreate(['employee_id' => $employee->id, 'year' => $d['year']], ['total_days' => $d['total_days']]);
        AuditLog::record($r->user(), 'leave.quota', $employee, $d);

        return ['message' => 'Jatah cuti disimpan.'];
    }

    public function approve(Request $r, int $id)
    {
        abort_unless(LeaveRequest::tableReady(), 503, self::MIGRATE_MSG);
        $d = $r->validate(['note' => 'nullable|string|max:300']);
        $req = LeaveRequest::findOrFail($id);
        abort_unless($req->status === 'pending', 422, 'Pengajuan ini sudah diproses.');

        $dates = LeaveRequest::workDates($req->start_date, $req->end_date);
        $annual = $req->type === 'tahunan';
        $status = $annual ? 'cuti' : 'izin';
        $label = $req->type_label . ': ' . $req->reason;

        $applied = DB::transaction(function () use ($req, $dates, $annual, $status, $label, $r, $d) {
            $existing = AttendanceRecord::where('employee_id', $req->employee_id)
                ->whereDate('attendance_date', '>=', $req->start_date->toDateString())
                ->whereDate('attendance_date', '<=', $req->end_date->toDateString())
                ->get()->keyBy(fn ($a) => $a->attendance_date->toDateString());

            // Hari yang sudah check-in tidak diubah.
            $targets = array_values(array_filter($dates, fn ($date) => !$existing->get($date)?->check_in_at));

            if ($annual) {
                $year = $req->start_date->year;
                $new = count(array_filter($targets, fn ($date) => $existing->get($date)?->attendance_status !== 'cuti'));
                $total = LeaveQuota::totalFor($req->employee_id, $year);
                $used = LeaveQuota::usedFor($req->employee_id, $year);
                abort_if($used + $new > $total, 422, "Jatah cuti tahun $year tidak cukup: terpakai $used dari $total hari, pengajuan ini menambah $new hari. Naikkan jatah karyawan lebih dulu bila memang disetujui.");
            }

            foreach ($targets as $date) {
                $rec = $existing->get($date) ?? new AttendanceRecord(['employee_id' => $req->employee_id, 'attendance_date' => $date]);
                $rec->attendance_status = $status;
                $rec->notes = mb_substr($label, 0, 300);
                $rec->save();
            }

            $req->update(['status' => 'approved', 'reviewed_by' => $r->user()->id, 'reviewed_at' => now(), 'review_note' => $d['note'] ?? null]);

            return count($targets);
        });

        AuditLog::record($r->user(), 'leave.approve', $req, ['days_marked' => $applied]);
        $skipped = count($dates) - $applied;

        return ['message' => 'Pengajuan disetujui, ' . $applied . ' hari ditandai di absensi.' . ($skipped > 0 ? " $skipped hari dilewati karena karyawan sudah check-in." : '')];
    }

    public function reject(Request $r, int $id)
    {
        abort_unless(LeaveRequest::tableReady(), 503, self::MIGRATE_MSG);
        $d = $r->validate(['note' => 'required|string|min:3|max:300'], ['note.required' => 'Alasan penolakan wajib diisi.', 'note.min' => 'Alasan penolakan minimal 3 karakter.']);
        $req = LeaveRequest::findOrFail($id);
        abort_unless($req->status === 'pending', 422, 'Pengajuan ini sudah diproses.');
        $req->update(['status' => 'rejected', 'reviewed_by' => $r->user()->id, 'reviewed_at' => now(), 'review_note' => $d['note']]);
        AuditLog::record($r->user(), 'leave.reject', $req, ['note' => $d['note']]);

        return ['message' => 'Pengajuan ditolak.'];
    }

    public function attachment(int $id)
    {
        $req = LeaveRequest::findOrFail($id);
        abort_unless($req->attachment_path && Storage::disk('local')->exists($req->attachment_path), 404, 'Lampiran tidak ada.');

        return Storage::disk('local')->download($req->attachment_path, $req->attachment_name ?: 'lampiran');
    }
}
