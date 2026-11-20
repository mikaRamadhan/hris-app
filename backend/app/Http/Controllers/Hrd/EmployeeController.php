<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    private function rules(?Employee $e = null): array
    {
        return [
            'employee_number' => ['required', 'string', 'max:30', Rule::unique('employees', 'employee_number')->ignore($e?->id)],
            'full_name' => 'required|string|max:100',
            'email' => 'required|email|max:150',
            'phone' => 'nullable|string|max:25',
            'address' => 'nullable|string|max:500',
            'birth_date' => 'nullable|date',
            'gender' => 'nullable|in:Laki-laki,Perempuan',
            'department_id' => 'nullable|exists:organizational_units,id',
            'position_id' => 'nullable|exists:organizational_units,id',
            'employee_status_id' => 'nullable|exists:employee_statuses,id',
            'join_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:join_date',
            'is_active' => 'boolean',
        ];
    }

    private function query(Request $r)
    {
        return Employee::with(['department:id,name', 'position:id,name', 'status:id,name'])->withExists(['faceProfile as has_face_profile'])
            ->when($r->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('full_name', 'like', "%$s%")
                ->orWhere('employee_number', 'like', "%$s%")->orWhere('email', 'like', "%$s%")))
            ->when($r->department_id, fn ($q, $v) => $q->where('department_id', $v))
            ->when($r->employee_status_id, fn ($q, $v) => $q->where('employee_status_id', $v))
            ->when($r->filled('is_active'), fn ($q) => $q->where('is_active', $r->boolean('is_active')))
            ->orderBy('full_name');
    }

    public function index(Request $r)
    {
        return $this->query($r)->paginate(15);
    }

    public function show(int $id)
    {
        return Employee::with(['department:id,name', 'position:id,name', 'status:id,name', 'faceProfile:id,employee_id,registered_at,is_active'])->findOrFail($id);
    }

    public function store(Request $r)
    {
        $e = Employee::create($r->validate($this->rules()));
        AuditLog::record($r->user(), 'employee.create', $e);

        return response()->json($e, 201);
    }

    public function update(Request $r, int $id)
    {
        $e = Employee::findOrFail($id);
        $e->update($r->validate($this->rules($e)));
        AuditLog::record($r->user(), 'employee.update', $e);

        return $e;
    }

    /** Karyawan tidak dihapus; dinonaktifkan agar riwayat absensi tetap terjaga. */
    public function destroy(Request $r, int $id)
    {
        $e = Employee::findOrFail($id);
        $e->update(['is_active' => false, 'end_date' => $e->end_date ?? today()]);
        AuditLog::record($r->user(), 'employee.deactivate', $e);

        return ['message' => 'Karyawan dinonaktifkan.'];
    }

    public function export(Request $r)
    {
        $rows = $this->query($r)->get();
        AuditLog::record($r->user(), 'employee.export', null, ['count' => $rows->count()]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['No. Karyawan', 'Nama', 'Email', 'Telepon', 'Departemen', 'Jabatan', 'Status', 'Tanggal Masuk', 'Aktif']);
            foreach ($rows as $e) {
                fputcsv($out, [$e->employee_number, $e->full_name, $e->email, $e->phone, $e->department?->name, $e->position?->name,
                    $e->status?->name, $e->join_date?->toDateString(), $e->is_active ? 'Ya' : 'Tidak']);
            }
            fclose($out);
        }, 'data-karyawan.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function facePhoto(Request $r, int $id)
    {
        $e = Employee::withTrashed()->with('faceProfile')->findOrFail($id);
        $path = $e->faceProfile?->photo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'Foto registrasi tidak ada.');
        AuditLog::record($r->user(), 'face.photo.view', $e);

        return Storage::disk('local')->response($path);
    }
}
