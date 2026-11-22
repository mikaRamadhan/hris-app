<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\ApplicantController;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\SelectionResult;
use App\Services\CvMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApplicationController extends Controller
{
    public function index(Request $r)
    {
        $q = Application::with(['applicant:id,full_name,education', 'vacancy:id,title'])
            ->when($r->vacancy_id, fn ($q, $v) => $q->where('vacancy_id', $v))
            ->when($r->status, fn ($q, $s) => $q->where('status', $s))
            ->when($r->q, fn ($q, $s) => $q->whereHas('applicant', fn ($w) => $w->where('full_name', 'like', "%$s%")))
            ->when($r->filled('min_score'), fn ($q) => $q->where('cv_score', '>=', (float) $r->min_score))
            ->when($r->sort === 'score', fn ($q) => $q->orderByDesc('cv_score'))
            ->latest();

        return $q->paginate(15)->through(fn (Application $a) => [
            'id' => $a->id, 'applicant' => $a->applicant->full_name, 'education' => $a->applicant->education,
            'vacancy' => $a->vacancy?->title, 'status' => $a->status, 'announced' => (bool) $a->announced_at, 'cv_score' => $a->cv_score,
            'created_at' => $a->created_at->toIso8601String(),
        ]);
    }

    public function show(int $id)
    {
        $a = Application::with(['applicant.user:id,email', 'vacancy', 'documents', 'results.decider:id,name'])->findOrFail($id);
        $employee = Employee::withTrashed()->where('applicant_id', $a->applicant_id)->first();

        return [
            'id' => $a->id, 'status' => $a->status, 'announced_at' => $a->announced_at?->toIso8601String(),
            'cover_letter' => $a->cover_letter, 'created_at' => $a->created_at->toIso8601String(),
            'vacancy' => ['id' => $a->vacancy?->id, 'title' => $a->vacancy?->title, 'quota' => $a->vacancy?->quota],
            'applicant' => $a->applicant->toArray() + ['email' => $a->applicant->user?->email],
            'documents' => $a->documents->map(fn ($d) => ['id' => $d->id, 'type' => $d->type, 'original_name' => $d->original_name, 'size' => $d->size]),
            'results' => $a->results->map(fn ($x) => [
                'id' => $x->id, 'stage' => $x->stage, 'result' => $x->result, 'notes' => $x->notes,
                'is_announced' => $x->is_announced, 'by' => $x->decider?->name, 'at' => $x->created_at->toIso8601String(),
            ]),
            'employee_id' => $employee?->id,
            'cv' => [
                'keywords_set' => count($a->vacancy?->cv_keywords ?? []) > 0,
                'score' => $a->cv_score,
                'match' => $a->cv_match,
            ],
        ];
    }

    /** Hitung ulang kecocokan CV (ekstraksi teks diulang), mis. setelah pustaka PDF dipasang. */
    public function rescan(int $id)
    {
        $a = Application::findOrFail($id);
        ApplicationDocument::where('application_id', $a->id)->where('type', 'cv')->update(['extracted_text' => null]);
        CvMatcher::evaluate($a);

        return $this->show($id);
    }

    public function document(int $id, int $docId)
    {
        $doc = ApplicationDocument::where('application_id', $id)->findOrFail($docId);

        return ApplicantController::download($doc);
    }

    /** Mencatat hasil seleksi. Tahap kedua (wawancara) tidak wajib; HRD memilih tahap sesuai proses. */
    public function addResult(Request $r, int $id)
    {
        $a = Application::findOrFail($id);
        if ($a->announced_at) {
            return response()->json(['message' => 'Hasil sudah diumumkan dan tidak dapat diubah.'], 422);
        }
        $data = $r->validate([
            'stage' => 'required|in:screening,interview,final',
            'result' => 'required|in:pass,fail,pending',
            'notes' => 'nullable|string|max:2000',
        ]);

        DB::transaction(function () use ($a, $data, $r) {
            SelectionResult::create($data + ['application_id' => $a->id, 'decided_by' => $r->user()->id]);
            $a->status = match (true) {
                $data['result'] === 'fail' => 'rejected',
                $data['stage'] === 'final' && $data['result'] === 'pass' => 'accepted',
                $data['stage'] === 'interview' => 'interview',
                default => 'reviewing',
            };
            $a->save();
            AuditLog::record($r->user(), 'selection.result', $a, $data);
        });

        return $this->show($id);
    }

    public function announce(Request $r, int $id)
    {
        $a = Application::findOrFail($id);
        if (!in_array($a->status, ['accepted', 'rejected'], true)) {
            return response()->json(['message' => 'Hanya hasil akhir (diterima/ditolak) yang dapat diumumkan.'], 422);
        }
        DB::transaction(function () use ($a, $r) {
            $a->update(['announced_at' => now()]);
            $a->results()->update(['is_announced' => true]);
            AuditLog::record($r->user(), 'selection.announce', $a, ['status' => $a->status]);
        });

        return $this->show($id);
    }

    /** Konversi pelamar yang diterima menjadi karyawan; keterkaitan riwayat rekrutmen dipertahankan. */
    public function convert(Request $r, int $id)
    {
        $a = Application::with(['applicant.user', 'vacancy'])->findOrFail($id);
        if ($a->status !== 'accepted') {
            return response()->json(['message' => 'Hanya pelamar berstatus diterima yang dapat dikonversi.'], 422);
        }
        if (Employee::withTrashed()->where('applicant_id', $a->applicant_id)->exists()) {
            return response()->json(['message' => 'Pelamar ini sudah dikonversi menjadi karyawan.'], 422);
        }
        $data = $r->validate([
            'department_id' => 'nullable|exists:organizational_units,id',
            'position_id' => 'nullable|exists:organizational_units,id',
            'employee_status_id' => 'required|exists:employee_statuses,id',
            'join_date' => 'required|date',
        ]);

        $employee = DB::transaction(function () use ($a, $data, $r) {
            $p = $a->applicant;
            $employee = Employee::create($data + [
                'user_id' => $p->user_id, 'applicant_id' => $p->id, 'employee_number' => Employee::nextNumber(),
                'full_name' => $p->full_name, 'email' => $p->user->email, 'phone' => $p->phone, 'address' => $p->address,
                'birth_date' => $p->birth_date, 'gender' => $p->gender, 'is_active' => true,
            ]);
            $p->user->update(['role' => 'karyawan']);
            AuditLog::record($r->user(), 'employee.convert', $employee, ['application_id' => $a->id]);

            return $employee;
        });

        return response()->json($employee, 201);
    }
}
