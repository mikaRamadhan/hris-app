<?php

namespace App\Services;

use App\Models\AttendanceAttempt;
use App\Models\AttendanceLocation;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public const LATE_REASONS = ['sakit' => 'Sakit', 'izin' => 'Izin'];
    public const EARLY_REASONS = ['sakit' => 'Sakit', 'izin' => 'Izin', 'kunjungan' => 'Kunjungan'];

    /**
     * Memproses check-in / check-out. Absensi hanya berhasil jika lokasi valid, wajah cocok, dan liveness lulus.
     * Waktu yang dipakai selalu waktu server.
     */
    public function process(Employee $employee, string $type, array $in, Request $request): AttendanceRecord
    {
        $field = $type === 'check_in' ? 'check_in' : 'check_out';
        $today = now()->toDateString();

        $record = AttendanceRecord::where('employee_id', $employee->id)->where('attendance_date', $today)->first();
        if ($type === 'check_in' && $record && $record->check_in_at) {
            $this->abort('Anda sudah check-in hari ini.', 409);
        }
        if ($type === 'check_out') {
            if (!$record || !$record->check_in_at) {
                $this->abort('Anda belum check-in hari ini.', 409);
            }
            if ($record->check_out_at) {
                $this->abort('Anda sudah check-out hari ini.', 409);
            }
        }

        $profile = $employee->faceProfile;
        if (!$profile || !$profile->is_active) {
            $this->abort('Profil wajah belum terdaftar. Daftarkan wajah Anda terlebih dahulu.', 422);
        }

        // 1. Lokasi
        $lat = $in['latitude'] ?? null;
        $lng = $in['longitude'] ?? null;
        $accuracy = $in['accuracy'] ?? null;
        $location = null;
        $distance = null;
        $locationOk = false;
        $locationReason = null;

        if ($lat === null || $lng === null) {
            $locationReason = 'Lokasi tidak tersedia. Izinkan akses lokasi pada perangkat atau hubungi HRD untuk prosedur alternatif.';
        } elseif ($accuracy !== null && $accuracy > config('hris.max_gps_accuracy')) {
            $locationReason = 'Akurasi lokasi terlalu rendah (' . round($accuracy) . ' m). Coba lagi di tempat terbuka.';
        } else {
            foreach (AttendanceLocation::where('is_active', true)->get() as $loc) {
                $d = Geo::distanceMeters((float) $lat, (float) $lng, $loc->latitude, $loc->longitude);
                if ($distance === null || $d < $distance) {
                    $distance = $d;
                    $location = $loc;
                }
            }
            if (!$location) {
                $locationReason = 'Belum ada lokasi absensi aktif. Hubungi HRD.';
            } elseif ($distance > $location->radius_meters) {
                $locationReason = 'Ditolak: di luar radius lokasi (' . round($distance) . ' m dari ' . $location->location_name . ').';
            } else {
                $locationOk = true;
            }
        }

        // 2. Wajah dan liveness
        [$faceOk] = FaceMatcher::matches($profile->face_embedding, $in['descriptor']);
        $livenessOk = (bool) ($in['liveness_passed'] ?? false);

        $reason = null;
        if (!$locationOk) {
            $reason = $locationReason;
        } elseif (config('hris.require_liveness', false) && !$livenessOk) {
            $reason = 'Ditolak: pemeriksaan keaslian gagal.';
        } elseif (!$faceOk) {
            $reason = 'Ditolak: wajah tidak cocok.';
        }

        // Status jam kerja (WIB mengikuti APP_TIMEZONE=Asia/Jakarta)
        $now = now();
        $at = fn (string $hm) => $now->copy()->setTimeFromTimeString($hm);
        if ($type === 'check_in') {
            $timeStatus = $now->lte($at(config('hris.check_in_on_time', '09:00'))->endOfMinute()) ? 'on_time'
                : ($now->lte($at(config('hris.check_in_tolerance', '09:30'))->endOfMinute()) ? 'tolerance' : 'late');
        } else {
            $timeStatus = $now->gte($at(config('hris.check_out_from', '17:00'))->startOfMinute()) ? 'normal' : 'early_leave';
        }

        $needsReason = ($type === 'check_in' && $timeStatus === 'late') || ($type === 'check_out' && $timeStatus === 'early_leave');
        $options = $type === 'check_in' ? self::LATE_REASONS : self::EARLY_REASONS;
        $reasonType = $in['reason_type'] ?? null;
        if ($reason === null && $needsReason && (!$reasonType || !isset($options[$reasonType]))) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => $type === 'check_in' ? 'Anda terlambat. Pilih alasan keterlambatan.' : 'Anda pulang sebelum jam kerja berakhir. Pilih alasan.',
                'reason_required' => true,
                'options' => collect($options)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            ], 422));
        }

        $attempt = [
            'employee_id' => $employee->id, 'type' => $type, 'attendance_location_id' => $location?->id,
            'latitude' => $lat, 'longitude' => $lng, 'distance_meters' => $distance !== null ? (int) round($distance) : null,
            'location_ok' => $locationOk, 'face_ok' => $faceOk, 'liveness_ok' => $livenessOk,
            'success' => $reason === null, 'reason' => $reason, 'ip' => $request->ip(),
        ];
        AttendanceAttempt::create($attempt);

        if ($reason !== null) {
            $this->abort($reason, 422);
        }

        return DB::transaction(function () use ($employee, $type, $field, $today, $location, $lat, $lng, $distance, $livenessOk, $timeStatus, $in, $reasonType, $needsReason) {
            $record = AttendanceRecord::where('employee_id', $employee->id)->where('attendance_date', $today)->lockForUpdate()->first();

            if ($type === 'check_in' && $record?->check_in_at) {
                $this->abort('Anda sudah check-in hari ini.', 409);
            }
            if ($type === 'check_out' && (!$record || $record->check_out_at)) {
                $this->abort('Check-out tidak dapat diproses.', 409);
            }

            $record ??= new AttendanceRecord(['employee_id' => $employee->id, 'attendance_date' => $today]);
            $record->attendance_location_id = $location->id;
            $record->{$field . '_at'} = now();
            $record->{$field . '_latitude'} = $lat;
            $record->{$field . '_longitude'} = $lng;
            $record->{$field . '_distance_meters'} = (int) round($distance);
            $record->{$field . '_location_verified'} = true;
            $record->{$field . '_face_verified'} = true;
            $record->{$field . '_liveness_passed'} = $livenessOk;
            $record->{$field . '_status'} = $timeStatus;
            $record->{$field . '_reason_type'} = $needsReason ? $reasonType : null;
            $record->{$field . '_reason_note'} = $needsReason ? mb_substr((string) ($in['reason_note'] ?? ''), 0, 300) ?: null : null;
            if (!empty($in['photo'])) {
                $record->{$field . '_photo'} = $in['photo']->storeAs('attendance/' . $employee->id, $today . '-' . ($type === 'check_in' ? 'in' : 'out') . '.jpg', 'local');
            }
            $record->attendance_status = $type === 'check_in' ? 'checked_in' : 'completed';
            $record->save();

            return $record;
        });
    }

    private function abort(string $message, int $status): never
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['message' => $message], $status));
    }
}
