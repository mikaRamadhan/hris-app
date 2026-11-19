<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Vacancy;
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
        ];
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
        $v = Vacancy::create($r->validate($this->rules()) + ['created_by' => $r->user()->id]);
        AuditLog::record($r->user(), 'vacancy.create', $v);

        return response()->json($v, 201);
    }

    public function update(Request $r, int $id)
    {
        $v = Vacancy::findOrFail($id);
        $v->update($r->validate($this->rules()));
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
