<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Vacancy;
use App\Services\CvMatcher;
use Illuminate\Http\Request;

class VacancyController extends Controller
{
    private function rules(): array
    {
        return [
            'title' => 'required|string|max:150',
            'organizational_unit_id' => 'nullable|exists:organizational_units,id',
            'location' => 'nullable|string|max:150',
            'description' => 'required|string',
            'requirements' => 'required|string',
            'quota' => 'required|integer|min:1|max:1000',
            'open_at' => 'required|date',
            'close_at' => 'required|date|after_or_equal:open_at',
            'status' => 'required|in:draft,open,closed',
            // Keyword kualifikasi CV yang dipilih HRD; bobot 1-5 (makin besar makin penting)
            'cv_keywords' => 'nullable|array|max:50',
            'cv_keywords.*.keyword' => 'required|string|max:60',
            'cv_keywords.*.weight' => 'nullable|integer|between:1,5',
        ];
    }

    /** Rapikan keyword: trim, buang duplikat (tanpa membedakan huruf besar/kecil), bobot default 1. */
    private function clean(array $data): array
    {
        $seen = [];
        $out = [];
        foreach ($data['cv_keywords'] ?? [] as $k) {
            $word = trim($k['keyword']);
            $key = mb_strtolower($word);
            if ($word === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['keyword' => $word, 'weight' => (int) ($k['weight'] ?? 1) ?: 1];
        }
        $data['cv_keywords'] = $out ?: null;

        return $data;
    }

    public function index()
    {
        return Vacancy::with('unit:id,name')->withCount(['applications', 'applications as accepted_count' => fn ($q) => $q->where('status', 'accepted')])
            ->latest()->get();
    }

    public function show(int $id)
    {
        return Vacancy::with('unit:id,name')->withCount('applications')->findOrFail($id);
    }

    public function store(Request $r)
    {
        $v = Vacancy::create($this->clean($r->validate($this->rules())) + ['created_by' => $r->user()->id]);
        AuditLog::record($r->user(), 'vacancy.create', $v);

        return response()->json($v, 201);
    }

    public function update(Request $r, int $id)
    {
        $v = Vacancy::findOrFail($id);
        $v->update($this->clean($r->validate($this->rules())));
        // Keyword bisa berubah: hitung ulang skor semua lamaran pada lowongan ini
        $v->applications()->with(['documents', 'applicant'])->get()->each(function ($a) use ($v) {
            $a->setRelation('vacancy', $v);
            CvMatcher::evaluate($a);
        });
        AuditLog::record($r->user(), 'vacancy.update', $v);

        return $v;
    }

    /** Lowongan yang sudah punya lamaran hanya ditutup; yang kosong diarsipkan (soft delete). */
    public function destroy(Request $r, int $id)
    {
        $v = Vacancy::withCount('applications')->findOrFail($id);
        if ($v->applications_count > 0) {
            $v->update(['status' => 'closed']);
            $msg = 'Lowongan memiliki lamaran sehingga hanya ditutup.';
        } else {
            $v->delete();
            $msg = 'Lowongan diarsipkan.';
        }
        AuditLog::record($r->user(), 'vacancy.close_or_archive', $v);

        return ['message' => $msg];
    }
}
