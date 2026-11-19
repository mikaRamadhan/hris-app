<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Controller;
use App\Models\AttendanceAttempt;
use App\Models\AttendanceLocation;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    // --- Lokasi absensi ---
    private function locRules(): array
    {
        return [
            'location_name' => 'required|string|max:120',
            'address' => 'nullable|string|max:500',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius_meters' => 'required|integer|min:10|max:5000',
            'is_active' => 'boolean',
        ];
    }

    public function locations()
    {
        return AttendanceLocation::orderBy('location_name')->get();
    }

    public function storeLocation(Request $r)
    {
        $l = AttendanceLocation::create($r->validate($this->locRules()) + ['created_by' => $r->user()->id]);
        AuditLog::record($r->user(), 'location.create', $l);

        return response()->json($l, 201);
    }

    public function updateLocation(Request $r, int $id)
    {
        $l = AttendanceLocation::findOrFail($id);
        $l->update($r->validate($this->locRules()));
        AuditLog::record($r->user(), 'location.update', $l);

        return $l;
    }

    public function destroyLocation(Request $r, int $id)
    {
        $l = AttendanceLocation::findOrFail($id);
        if (AttendanceRecord::where('attendance_location_id', $id)->exists()) {
            $l->update(['is_active' => false]);
            $msg = 'Lokasi sudah memiliki riwayat absensi sehingga hanya dinonaktifkan.';
        } else {
            $l->delete();
            $msg = 'Lokasi dihapus.';
        }
        AuditLog::record($r->user(), 'location.delete', $l);

        return ['message' => $msg];
    }

    // --- Rekap ---
    private function recordQuery(Request $r)
    {
        return AttendanceRecord::with(['employee:id,full_name,employee_number', 'location:id,location_name'])
            ->when($r->from, fn ($q, $v) => $q->whereDate('attendance_date', '>=', $v))
            ->when($r->to, fn ($q, $v) => $q->whereDate('attendance_date', '<=', $v))
            ->when($r->employee_id, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($r->location_id, fn ($q, $v) => $q->where('attendance_location_id', $v))
            ->orderByDesc('attendance_date')->orderByDesc('check_in_at');
    }

    public function records(Request $r)
    {
        return $this->recordQuery($r)->paginate(20);
    }

    public function attempts(Request $r)
    {
        return AttendanceAttempt::with('employee:id,full_name,employee_number')
            ->when($r->only_failed, fn ($q) => $q->where('success', false))
            ->latest()->paginate(20);
    }

    public function export(Request $r)
    {
        $rows = $this->recordQuery($r)->get();
        AuditLog::record($r->user(), 'attendance.export', null, ['count' => $rows->count()]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Tanggal', 'No. Karyawan', 'Nama', 'Lokasi', 'Check-in', 'Check-out', 'Jarak In (m)', 'Jarak Out (m)', 'Status']);
            foreach ($rows as $a) {
                fputcsv($out, [$a->attendance_date->toDateString(), $a->employee?->employee_number, $a->employee?->full_name, $a->location?->location_name,
                    $a->check_in_at?->format('H:i:s'), $a->check_out_at?->format('H:i:s'), $a->check_in_distance_meters, $a->check_out_distance_meters,
                    $a->attendance_status === 'completed' ? 'Selesai' : 'Sudah check-in']);
            }
            fclose($out);
        }, 'rekap-absensi.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
