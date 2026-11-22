<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Controller;
use App\Models\AttendanceAttempt;
use App\Models\AttendanceLocation;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveQuota;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
    /** Rentang tanggal rekap (default hari ini, maksimal 31 hari). */
    private function dateRange(Request $r): array
    {
        $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $from = $r->from ? Carbon::parse($r->from)->startOfDay() : AttendanceRecord::bizToday();
        $to = $r->to ? Carbon::parse($r->to)->startOfDay() : $from->copy();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to, true) > 30) {
            $to = $from->copy()->addDays(30);
        }

        return [$from, $to];
    }

    private function recordQuery(Request $r, Carbon $from, Carbon $to)
    {
        return AttendanceRecord::with(['employee:id,full_name,employee_number,department_id', 'employee.department:id,name', 'location:id,location_name'])
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->when($r->location_id, fn ($q, $v) => $q->where('attendance_location_id', $v))
            ->when($r->search, fn ($q, $v) => $q->whereHas('employee', fn ($e) => $this->searchEmployee($e, $v)))
            ->when($r->department_id, fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $v)));
    }

    private function searchEmployee($q, string $v)
    {
        $like = '%' . mb_strtolower(trim($v)) . '%';

        return $q->where(fn ($w) => $w->whereRaw('LOWER(full_name) LIKE ?', [$like])->orWhereRaw('LOWER(employee_number) LIKE ?', [$like]));
    }

    /**
     * Seluruh karyawan aktif untuk setiap tanggal dalam rentang, lengkap dengan catatan absensinya.
     * Kategori: masuk (sudah check-in), belum (belum ada catatan), izin / tidak masuk, cuti.
     */
    private function rows(Request $r, bool $filterCategory = true): Collection
    {
        [$from, $to] = $this->dateRange($r);

        $records = $this->recordQuery($r, $from, $to)->get()
            ->keyBy(fn ($a) => $a->employee_id . '|' . $a->attendance_date->toDateString());

        $employees = Employee::with('department:id,name')->where('is_active', true)
            ->when($r->search, fn ($q, $v) => $this->searchEmployee($q, $v))
            ->when($r->department_id, fn ($q, $v) => $q->where('department_id', $v))
            ->get(['id', 'full_name', 'employee_number', 'department_id', 'join_date']);
        // Karyawan nonaktif / terhapus yang punya catatan pada rentang ini tetap ditampilkan.
        foreach ($records as $rec) {
            if ($rec->employee && !$employees->contains('id', $rec->employee_id)) {
                $employees->push($rec->employee);
            }
        }
        $employees = $employees->sortBy('full_name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        $rows = collect();
        for ($d = $to->copy(); $d->gte($from); $d->subDay()) {
            $date = $d->toDateString();
            foreach ($employees as $emp) {
                $rec = $records->get($emp->id . '|' . $date);
                if (!$rec) {
                    if ($r->location_id) {
                        continue; // lokasi hanya diketahui dari catatan absensi
                    }
                    if ($emp->join_date && $date < $emp->join_date->toDateString()) {
                        continue; // belum bergabung pada tanggal ini
                    }
                    $rec = new AttendanceRecord(['employee_id' => $emp->id, 'attendance_date' => $date]);
                    $rec->setRelation('employee', $emp);
                }
                $rows->push($rec);
            }
        }

        return $filterCategory && $r->category ? $rows->filter(fn ($a) => $a->presence_category === $r->category)->values() : $rows;
    }

    public function records(Request $r)
    {
        $all = $this->rows($r, false);
        $summary = ['total' => $all->count()];
        foreach (['masuk', 'belum', 'alpa', 'izin', 'cuti', 'libur'] as $k) {
            $summary[$k] = $all->where('presence_category', $k)->count();
        }

        $rows = $r->category ? $all->filter(fn ($a) => $a->presence_category === $r->category)->values() : $all;
        $page = max(1, (int) $r->page);
        $per = 50;
        $pager = new LengthAwarePaginator($rows->forPage($page, $per)->values(), $rows->count(), $per, $page);
        $now = AttendanceRecord::bizNow();

        return array_merge($pager->toArray(), [
            'summary' => $summary,
            // Dipakai tampilan untuk memberi petunjuk "melewati batas masuk" pada karyawan yang belum check-in hari ini.
            'hours' => [
                'today' => $now->toDateString(),
                'now' => $now->format('H:i'),
                'on_time' => config('hris.check_in_on_time', '09:00'),
                'tolerance' => config('hris.check_in_tolerance', '09:30'),
                'check_out_from' => config('hris.check_out_from', '17:00'),
            ],
        ]);
    }

    /**
     * Statistik absensi per bulan (month 1-12) atau per tahun (month 0 = Januari-Desember), semua karyawan aktif.
     * Hari kerja = Senin-Jumat (config hris.work_days), dihitung sampai hari ini dan sejak karyawan bergabung.
     * "Tanpa keterangan" = hari kerja yang sudah lewat tanpa check-in, izin, maupun cuti.
     * Persentase kehadiran = hadir / (hari kerja - cuti).
     */
    public function stats(Request $r)
    {
        $r->validate(['year' => 'nullable|integer|min:2000|max:2100', 'month' => 'nullable|integer|min:0|max:12']);
        $today = AttendanceRecord::bizToday();
        $todayStr = $today->toDateString();
        $year = (int) ($r->year ?: $today->year);
        $month = $r->month === null || $r->month === '' ? $today->month : (int) $r->month;

        $from = Carbon::create($year, $month ?: 1, 1, 0, 0, 0);
        $to = $month ? $from->copy()->endOfMonth()->startOfDay() : Carbon::create($year, 12, 31, 0, 0, 0);
        $workDays = (array) config('hris.work_days', [1, 2, 3, 4, 5]);

        // Setiap tanggal dalam rentang: [tanggal => apakah hari kerja]
        $dates = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $dates[$d->toDateString()] = in_array($d->dayOfWeekIso, $workDays, true);
        }

        $employees = Employee::with('department:id,name')->where('is_active', true)
            ->when($r->search, fn ($q, $v) => $this->searchEmployee($q, $v))
            ->when($r->department_id, fn ($q, $v) => $q->where('department_id', $v))
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_number', 'department_id', 'join_date']);

        $records = AttendanceRecord::whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get()->groupBy('employee_id');

        $blank = ['tepat_waktu' => 0, 'toleransi' => 0, 'terlambat' => 0, 'izin' => 0, 'cuti' => 0, 'tanpa' => 0, 'belum' => 0];
        $daily = [];
        foreach ($dates as $date => $isWork) {
            $daily[$date] = $blank + ['date' => $date, 'work' => $isWork, 'future' => $date > $todayStr];
        }
        $totals = ['hadir' => 0, 'tepat_waktu' => 0, 'toleransi' => 0, 'terlambat' => 0, 'pulang_awal' => 0, 'izin' => 0, 'cuti' => 0, 'tanpa_keterangan' => 0, 'hari_kerja' => 0];

        $rows = $employees->map(function ($e) use ($records, $dates, $todayStr, &$daily, &$totals) {
            $start = $e->join_date ? $e->join_date->toDateString() : '0000-00-00';
            $byDate = $records->get($e->id, collect())->keyBy(fn ($a) => $a->attendance_date->toDateString());
            $c = ['hadir' => 0, 'tepat_waktu' => 0, 'toleransi' => 0, 'terlambat' => 0, 'pulang_awal' => 0, 'izin' => 0, 'cuti' => 0, 'tanpa' => 0, 'due' => 0];

            foreach ($dates as $date => $isWork) {
                if ($date > $todayStr) {
                    break;
                }
                $rec = $byDate->get($date);
                if (!$rec && $date < $start) {
                    continue;
                }
                if ($rec && $rec->check_in_at) {
                    $sub = ['on_time' => 'tepat_waktu', 'tolerance' => 'toleransi', 'late' => 'terlambat'][$rec->check_in_status] ?? 'tepat_waktu';
                    $c['hadir']++;
                    $c[$sub]++;
                    $daily[$date][$sub]++;
                    if ($rec->check_out_status === 'early_leave') {
                        $c['pulang_awal']++;
                    }
                    $isWork && $c['due']++;
                } elseif ($rec && in_array($rec->attendance_status, ['izin', 'cuti'], true)) {
                    $c[$rec->attendance_status]++;
                    $daily[$date][$rec->attendance_status]++;
                    $isWork && $c['due']++;
                } elseif ($isWork) {
                    if ($date < $todayStr) {
                        $c['tanpa']++;
                        $daily[$date]['tanpa']++;
                        $c['due']++;
                    } else {
                        $daily[$date]['belum']++; // hari ini, masih ada waktu untuk check-in
                    }
                }
            }

            $base = $c['due'] - $c['cuti'];
            foreach (['hadir', 'tepat_waktu', 'toleransi', 'terlambat', 'pulang_awal', 'izin', 'cuti'] as $k) {
                $totals[$k] += $c[$k];
            }
            $totals['tanpa_keterangan'] += $c['tanpa'];
            $totals['hari_kerja'] += $c['due'];

            return [
                'employee' => ['id' => $e->id, 'full_name' => $e->full_name, 'employee_number' => $e->employee_number, 'department' => $e->department?->name],
                'hari_kerja' => $c['due'],
                'hadir' => $c['hadir'],
                'tepat_waktu' => $c['tepat_waktu'],
                'toleransi' => $c['toleransi'],
                'terlambat' => $c['terlambat'],
                'pulang_awal' => $c['pulang_awal'],
                'izin' => $c['izin'],
                'cuti' => $c['cuti'],
                'tanpa_keterangan' => $c['tanpa'],
                'persen' => $base > 0 ? min(100, (int) round($c['hadir'] / $base * 100)) : null,
            ];
        })->values();

        $base = $totals['hari_kerja'] - $totals['cuti'];
        $totals['persen'] = $base > 0 ? min(100, (int) round($totals['hadir'] / $base * 100)) : null;

        // Deret grafik: per hari (satu bulan) atau per bulan (setahun)
        if ($month) {
            $series = collect($daily)->map(fn ($d) => $d + ['label' => (string) (int) substr($d['date'], 8, 2)])->values();
        } else {
            $names = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
            $series = collect(range(1, 12))->map(function ($m) use ($daily, $names, $blank, $todayStr, $year) {
                $key = sprintf('%04d-%02d', $year, $m);
                $acc = $blank + ['date' => $key . '-01', 'work' => true, 'future' => $key . '-01' > $todayStr, 'label' => $names[$m - 1]];
                foreach ($daily as $date => $d) {
                    if (str_starts_with($date, $key)) {
                        foreach (array_keys($blank) as $k) {
                            $acc[$k] += $d[$k];
                        }
                    }
                }

                return $acc;
            })->values();
        }

        $minYear = AttendanceRecord::min('attendance_date');
        $minYear = $minYear ? (int) substr((string) $minYear, 0, 4) : $today->year;

        return [
            'year' => $year,
            'month' => $month,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'granularity' => $month ? 'day' : 'month',
            'years' => range(min($minYear, $today->year - 1), max($today->year, $year)),
            'employees' => $employees->count(),
            'series' => $series,
            'data' => $rows,
            'totals' => $totals,
        ];
    }

    /** HRD menandai karyawan izin / tidak masuk atau cuti pada tanggal tertentu (atau mengembalikannya ke belum absen). */
    public function mark(Request $r)
    {
        $d = $r->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'date' => 'required|date',
            'status' => 'required|in:izin,cuti,belum',
            'notes' => 'nullable|string|max:300',
        ]);
        $date = Carbon::parse($d['date'])->toDateString();
        $rec = AttendanceRecord::where('employee_id', $d['employee_id'])->whereDate('attendance_date', $date)->first();
        abort_if($rec?->check_in_at, 422, 'Karyawan sudah check-in pada tanggal ini, status tidak dapat diubah.');

        if ($d['status'] === 'belum') {
            if ($rec) {
                AuditLog::record($r->user(), 'attendance.unmark', $rec, ['date' => $date]);
                $rec->delete();
            }

            return ['message' => 'Dikembalikan ke belum absen.'];
        }

        if ($d['status'] === 'cuti') {
            $year = Carbon::parse($date)->year;
            $total = LeaveQuota::totalFor($d['employee_id'], $year);
            $used = LeaveQuota::usedFor($d['employee_id'], $year, $rec?->id);
            abort_if($used >= $total, 422, "Jatah cuti tahun $year sudah habis ($used dari $total hari). Ubah jatah di tab Jatah Cuti jika perlu.");
        }

        $rec ??= new AttendanceRecord(['employee_id' => $d['employee_id'], 'attendance_date' => $date]);
        $rec->attendance_status = $d['status'];
        $rec->notes = $d['notes'] ?? null;
        $rec->save();
        AuditLog::record($r->user(), 'attendance.mark', $rec, ['status' => $d['status'], 'date' => $date]);

        return ['message' => $d['status'] === 'cuti' ? 'Ditandai cuti.' : 'Ditandai izin / tidak masuk.'];
    }

    public function attempts(Request $r)
    {
        return AttendanceAttempt::with('employee:id,full_name,employee_number')
            ->when($r->only_failed, fn ($q) => $q->where('success', false))
            ->latest()->paginate(20);
    }

    public function export(Request $r)
    {
        $rows = $this->rows($r);
        AuditLog::record($r->user(), 'attendance.export', null, ['count' => $rows->count()]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Tanggal', 'No. Karyawan', 'Nama', 'Lokasi', 'Check-in', 'Check-out', 'Jarak In (m)', 'Jarak Out (m)', 'Status Check-in', 'Alasan Check-in', 'Status Check-out', 'Alasan Check-out', 'Kehadiran', 'Telat (menit)', 'Pulang awal (menit)', 'Lama kerja (menit)', 'Keterangan HRD', 'Departemen']);
            foreach ($rows as $a) {
                fputcsv($out, [$a->attendance_date->toDateString(), $a->employee?->employee_number, $a->employee?->full_name, $a->location?->location_name,
                    $a->check_in_at?->format('H:i:s'), $a->check_out_at?->format('H:i:s'), $a->check_in_distance_meters, $a->check_out_distance_meters,
                    ['on_time' => 'Tepat waktu', 'tolerance' => 'Toleransi', 'late' => 'Terlambat'][$a->check_in_status] ?? '-',
                    trim(($a->check_in_reason_type ?? '') . ' ' . ($a->check_in_reason_note ?? '')),
                    ['normal' => 'Normal', 'early_leave' => 'Izin (pulang awal)'][$a->check_out_status] ?? '-',
                    trim(($a->check_out_reason_type ?? '') . ' ' . ($a->check_out_reason_note ?? '')),
                    ['masuk' => 'Masuk', 'belum' => 'Belum absen', 'alpa' => 'Tanpa keterangan', 'izin' => 'Izin / Tidak masuk', 'cuti' => 'Cuti', 'libur' => 'Libur'][$a->presence_category] ?? '-',
                    $a->late_minutes ?: '', $a->early_minutes ?: '', $a->work_minutes, $a->notes,
                    $a->employee?->department?->name]);
            }
            fclose($out);
        }, 'rekap-absensi.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function photo(Request $r, int $id, string $which)
    {
        abort_unless(in_array($which, ['in', 'out'], true), 404);
        $rec = AttendanceRecord::findOrFail($id);
        $path = $rec->getRawOriginal($which === 'in' ? 'check_in_photo' : 'check_out_photo');
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'Foto tidak ada.');
        AuditLog::record($r->user(), 'attendance.photo.view', $rec, ['which' => $which]);

        return Storage::disk('local')->response($path);
    }
}
