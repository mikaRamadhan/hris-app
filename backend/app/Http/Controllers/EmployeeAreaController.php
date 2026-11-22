<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLocation;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\EmployeeFaceProfile;
use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        $today = AttendanceRecord::where('employee_id', $e->id)->whereDate('attendance_date', AttendanceRecord::bizToday())->first();

        return [
            'employee' => $e->makeHidden('faceProfile'),
            'face_registered' => (bool) ($e->faceProfile && $e->faceProfile->is_active),
            'face_registered_at' => $e->faceProfile?->registered_at,
            'face_has_photo' => filled($e->faceProfile?->photo_path),
            'today' => $today,
            'locations' => AttendanceLocation::where('is_active', true)->get(['id', 'location_name', 'latitude', 'longitude', 'radius_meters']),
            'server_time' => now()->toIso8601String(),
            'face_threshold' => config('hris.face_threshold'),
        ];
    }

    /** Pada unggahan multipart, descriptor dikirim sebagai string JSON. */
    private function decodeDescriptor(Request $r): void
    {
        if (is_string($r->input('descriptor'))) {
            $r->merge(['descriptor' => json_decode($r->input('descriptor'), true)]);
        }
    }

    private function descriptorRules(): array
    {
        return ['descriptor' => 'required|array|size:128', 'descriptor.*' => 'required|numeric|between:-5,5'];
    }

    public function registerFace(Request $r)
    {
        $e = $this->employee($r);
        $this->decodeDescriptor($r);
        $data = $r->validate($this->descriptorRules() + [
            'consent' => 'accepted',
            'photo' => 'required|image|mimes:jpeg,jpg,png|max:1024',
        ]);

        $existing = $e->faceProfile;
        $now = now();
        $photoPath = $data['photo']->storeAs('faces', $e->id . '.jpg', 'local');
        EmployeeFaceProfile::updateOrCreate(['employee_id' => $e->id], [
            'photo_path' => $photoPath,
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
        if ($e->faceProfile?->photo_path) {
            Storage::disk('local')->delete($e->faceProfile->photo_path);
        }
        $e->faceProfile?->delete();
        AuditLog::record($r->user(), 'face.delete', $e);

        return ['message' => 'Data wajah dihapus.'];
    }

    public function attend(Request $r, string $type, AttendanceService $service)
    {
        abort_unless(in_array($type, ['check-in', 'check-out'], true), 404);
        $e = $this->employee($r);
        $this->decodeDescriptor($r);
        $data = $r->validate($this->descriptorRules() + [
            'photo' => 'required|image|mimes:jpeg,jpg,png|max:1024',
            'reason_type' => 'nullable|string|max:20',
            'reason_note' => 'nullable|string|max:300',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'liveness_passed' => 'nullable|boolean',
        ]);

        $record = $service->process($e, str_replace('-', '_', $type), $data, $r);

        $message = $type === 'check-in'
            ? 'Check-in berhasil' . ['on_time' => ' (tepat waktu).', 'tolerance' => ' (dalam toleransi keterlambatan).', 'late' => ' (terlambat).'][$record->check_in_status]
            : ($record->check_out_status === 'early_leave' ? 'Check-out berhasil. Pulang sebelum jam kerja berakhir, dicatat sebagai izin.' : 'Check-out berhasil.');

        return response()->json([
            'message' => $message,
            'record' => $record->load('location:id,location_name'),
        ]);
    }

    public function history(Request $r)
    {
        $e = $this->employee($r);

        return AttendanceRecord::with('location:id,location_name')->where('employee_id', $e->id)
            ->orderByDesc('attendance_date')->paginate(15);
    }

    // --- Foto (privat, hanya milik sendiri) ---
    public function facePhoto(Request $r)
    {
        $e = $this->employee($r);
        $path = $e->faceProfile?->photo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'Foto registrasi tidak ada.');

        return Storage::disk('local')->response($path);
    }

    public function attendancePhoto(Request $r, int $id, string $which)
    {
        abort_unless(in_array($which, ['in', 'out'], true), 404);
        $e = $this->employee($r);
        $rec = AttendanceRecord::where('employee_id', $e->id)->findOrFail($id);
        $path = $rec->getRawOriginal($which === 'in' ? 'check_in_photo' : 'check_out_photo');
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'Foto tidak ada.');

        return Storage::disk('local')->response($path);
    }
}
