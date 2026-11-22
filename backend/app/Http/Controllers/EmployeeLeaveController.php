<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\LeaveQuota;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Karyawan: melihat sisa cuti, riwayat pengajuan, dan mengajukan cuti. */
class EmployeeLeaveController extends Controller
{
    private function employee(Request $r)
    {
        $e = $r->user()->employee()->first();
        abort_unless($e && $e->is_active, 403, 'Data karyawan tidak ditemukan atau tidak aktif.');
        abort_unless(LeaveRequest::tableReady() && LeaveQuota::tableReady(), 503, 'Fitur cuti belum siap. Hubungi HRD / jalankan "php artisan migrate".');

        return $e;
    }

    private function quota(int $employeeId, int $year): array
    {
        $total = LeaveQuota::totalFor($employeeId, $year);
        $used = LeaveQuota::usedFor($employeeId, $year);
        $pending = LeaveRequest::pendingAnnualDays($employeeId, $year);

        return [
            'year' => $year,
            'total' => $total,
            'used' => $used,
            'pending' => $pending,
            'remaining' => $total - $used,
            'available' => max(0, $total - $used - $pending), // yang masih bisa diajukan
        ];
    }

    public function index(Request $r)
    {
        $e = $this->employee($r);
        $today = AttendanceRecord::bizToday();

        return [
            'quota' => $this->quota($e->id, $today->year),
            'types' => LeaveRequest::typeList(),
            'work_days' => array_values((array) config('hris.work_days', [1, 2, 3, 4, 5])),
            'today' => $today->toDateString(),
            'sick_backdate_days' => 14,
            'requests' => LeaveRequest::with('reviewer:id,name')->where('employee_id', $e->id)->latest('id')->limit(100)->get(),
        ];
    }

    public function store(Request $r)
    {
        $e = $this->employee($r);
        $d = $r->validate([
            'type' => 'required|in:' . implode(',', array_keys(LeaveRequest::TYPES)),
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|min:3|max:500',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ], [
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'attachment.max' => 'Ukuran lampiran maksimal 2 MB.',
            'attachment.mimes' => 'Lampiran harus berformat JPG, PNG, atau PDF.',
            'reason.min' => 'Alasan minimal 3 karakter.',
        ]);

        $start = Carbon::parse($d['start_date'])->startOfDay();
        $end = Carbon::parse($d['end_date'])->startOfDay();
        $today = AttendanceRecord::bizToday();
        $earliest = $d['type'] === 'sakit' ? $today->copy()->subDays(14) : $today;
        abort_if($start->toDateString() < $earliest->toDateString(), 422, $d['type'] === 'sakit'
            ? 'Cuti sakit hanya dapat diajukan paling lambat 14 hari ke belakang.'
            : 'Tanggal mulai tidak boleh sebelum hari ini.');
        abort_if($start->year !== $end->year, 422, 'Pengajuan lintas tahun harap dipisah menjadi dua pengajuan.');
        abort_if($start->diffInDays($end) > 90, 422, 'Rentang cuti maksimal 90 hari.');

        $dates = LeaveRequest::workDates($start, $end);
        abort_if(count($dates) === 0, 422, 'Rentang tanggal tidak mengandung hari kerja.');

        $overlap = LeaveRequest::where('employee_id', $e->id)->whereIn('status', ['pending', 'approved'])
            ->whereDate('start_date', '<=', $end->toDateString())->whereDate('end_date', '>=', $start->toDateString())->exists();
        abort_if($overlap, 422, 'Sudah ada pengajuan cuti lain (menunggu / disetujui) pada rentang tanggal tersebut.');

        if (LeaveRequest::TYPES[$d['type']]['deducts']) {
            $q = $this->quota($e->id, $start->year);
            abort_if(count($dates) > $q['available'], 422, sprintf(
                'Jatah cuti tidak cukup. Diajukan %d hari kerja, jatah yang masih bisa diajukan %d hari (sisa %d, menunggu persetujuan %d).',
                count($dates), $q['available'], $q['remaining'], $q['pending']
            ));
        }

        $path = $name = null;
        if ($r->hasFile('attachment')) {
            $path = $r->file('attachment')->store("leave/{$e->id}", 'local');
            $name = $r->file('attachment')->getClientOriginalName();
        }

        $req = LeaveRequest::create([
            'employee_id' => $e->id,
            'type' => $d['type'],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days' => count($dates),
            'reason' => trim($d['reason']),
            'attachment_path' => $path,
            'attachment_name' => $name,
            'status' => 'pending',
        ]);

        return response()->json(['message' => 'Pengajuan cuti terkirim dan menunggu persetujuan HRD.', 'data' => $req], 201);
    }

    public function cancel(Request $r, int $id)
    {
        $e = $this->employee($r);
        $req = LeaveRequest::where('employee_id', $e->id)->findOrFail($id);
        abort_unless($req->status === 'pending', 422, 'Hanya pengajuan yang masih menunggu yang dapat dibatalkan.');
        $req->update(['status' => 'cancelled']);

        return ['message' => 'Pengajuan dibatalkan.'];
    }

    public function attachment(Request $r, int $id)
    {
        $e = $this->employee($r);
        $req = LeaveRequest::where('employee_id', $e->id)->findOrFail($id);
        abort_unless($req->attachment_path && Storage::disk('local')->exists($req->attachment_path), 404, 'Lampiran tidak ada.');

        return Storage::disk('local')->download($req->attachment_path, $req->attachment_name ?: 'lampiran');
    }
}
