<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLocation;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\EmployeeFaceProfile;
use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** Area karyawan: profil, registrasi wajah, absensi. */
class EmployeeAreaController extends Controller
{
    private function employee(Request $r)
    {
        $e = $r->user()->employee()->with(['department:id,name', 'position:id,name', 'status:id,name', 'faceProfile'])->first();
        abort_unless($e && $e->is_active, 403, 'Data karyawan tidak ditemukan atau tidak aktif.');

        return $e;
    }

    public function me(Request $r)
    {
        $e = $this->employee($r);
        $today = AttendanceRecord::where('employee_id', $e->id)->whereDate('attendance_date', today())->first();

        return [
            'employee' => $e->makeHidden('faceProfile'),
            'face_registered' => (bool) ($e->faceProfile && $e->faceProfile->is_active),
            'face_registered_at' => $e->faceProfile?->registered_at,
            'today' => $today,
            'locations' => AttendanceLocation::where('is_active', true)->get(['id', 'location_name', 'latitude', 'longitude', 'radius_meters']),
            'server_time' => now()->toIso8601String(),
            'face_threshold' => config('hris.face_threshold'),
        ];
    }

    private function descriptorRules(): array
    {
        return ['descriptor' => 'required|array|size:128', 'descriptor.*' => 'required|numeric|between:-5,5'];
    }

    public function registerFace(Request $r)
    {
        $e = $this->employee($r);
        $data = $r->validate($this->descriptorRules() + [
            'consent' => 'accepted',
            'password' => 'required|string',
        ]);
        if (!Hash::check($data['password'], $r->user()->password)) {
            return response()->json(['message' => 'Kata sandi salah.'], 422);
        }

        $existing = $e->faceProfile;
        $now = now();
        EmployeeFaceProfile::updateOrCreate(['employee_id' => $e->id], [
            'face_embedding' => array_map('floatval', $data['descriptor']),
            'model_version' => 'face-api-128d',
            'consented_at' => $now,
            'registered_at' => $existing?->registered_at ?? $now,
            'last_updated_at' => $now,
            'is_active' => true,
        ]);
        AuditLog::record($r->user(), $existing ? 'face.update' : 'face.register', $e);

        return response()->json(['message' => 'Wajah berhasil didaftarkan.'], 201);
    }

    /** Hak subjek data: karyawan dapat menghapus template wajahnya sendiri. */
    public function deleteFace(Request $r)
    {
        $e = $this->employee($r);
        $e->faceProfile?->delete();
        AuditLog::record($r->user(), 'face.delete', $e);

        return ['message' => 'Data wajah dihapus.'];
    }

    public function attend(Request $r, string $type, AttendanceService $service)
    {
        abort_unless(in_array($type, ['check-in', 'check-out'], true), 404);
        $e = $this->employee($r);
        $data = $r->validate($this->descriptorRules() + [
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'liveness_passed' => 'required|boolean',
        ]);

        $record = $service->process($e, str_replace('-', '_', $type), $data, $r);

        return response()->json([
            'message' => $type === 'check-in' ? 'Check-in berhasil.' : 'Check-out berhasil.',
            'record' => $record->load('location:id,location_name'),
        ]);
    }

    public function history(Request $r)
    {
        $e = $this->employee($r);

        return AttendanceRecord::with('location:id,location_name')->where('employee_id', $e->id)
            ->orderByDesc('attendance_date')->paginate(15);
    }
}
