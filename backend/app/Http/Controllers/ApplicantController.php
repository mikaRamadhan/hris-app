<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\Vacancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Area pelamar: profil, lamaran, dokumen. Pelamar hanya dapat mengakses data miliknya sendiri. */
class ApplicantController extends Controller
{
    private function applicant(Request $r)
    {
        return $r->user()->applicant()->firstOrFail();
    }

    private function ownApplication(Request $r, int $id): Application
    {
        return Application::where('applicant_id', $this->applicant($r)->id)->with(['vacancy', 'documents'])->findOrFail($id);
    }

    public function profile(Request $r)
    {
        return $this->applicant($r);
    }

    public function updateProfile(Request $r)
    {
        $data = $r->validate([
            'full_name' => 'required|string|max:100',
            'phone' => 'required|string|max:25',
            'address' => 'required|string|max:500',
            'birth_date' => 'nullable|date|before:today',
            'gender' => 'nullable|in:Laki-laki,Perempuan',
            'education' => 'required|string|max:100',
            'institution' => 'nullable|string|max:150',
            'skills' => 'nullable|string|max:1000',
            'summary' => 'nullable|string|max:2000',
        ]);
        $a = $this->applicant($r);
        $a->update($data);

        return $a->fresh();
    }

    public function applications(Request $r)
    {
        return Application::where('applicant_id', $this->applicant($r)->id)->with('vacancy:id,title')->latest()->get()
            ->map(fn (Application $a) => $this->shape($a));
    }

    public function application(Request $r, int $id)
    {
        $a = $this->ownApplication($r, $id)->load(['results' => fn ($q) => $q->where('is_announced', true)]);

        return $this->shape($a, true);
    }

    private function shape(Application $a, bool $detail = false): array
    {
        $out = [
            'id' => $a->id, 'vacancy_id' => $a->vacancy_id, 'vacancy_title' => $a->vacancy?->title,
            'status' => $a->publicStatus(), 'created_at' => $a->created_at->toIso8601String(),
        ];
        if ($detail) {
            $out += [
                'cover_letter' => $a->cover_letter,
                'documents' => $a->documents->map(fn ($d) => ['id' => $d->id, 'type' => $d->type, 'original_name' => $d->original_name, 'size' => $d->size]),
                'results' => $a->announced_at ? $a->results->map(fn ($x) => ['stage' => $x->stage, 'result' => $x->result, 'notes' => $x->notes, 'at' => $x->created_at->toIso8601String()]) : [],
            ];
        }

        return $out;
    }

    public function apply(Request $r, int $vacancyId)
    {
        $applicant = $this->applicant($r);
        if (!$applicant->isComplete()) {
            return response()->json(['message' => 'Lengkapi profil Anda (telepon, alamat, pendidikan) sebelum melamar.'], 422);
        }
        $vacancy = Vacancy::findOrFail($vacancyId);
        if (!$vacancy->isOpen()) {
            return response()->json(['message' => 'Lowongan sudah ditutup atau tidak tersedia.'], 422);
        }
        if (Application::where('applicant_id', $applicant->id)->where('vacancy_id', $vacancy->id)->exists()) {
            return response()->json(['message' => 'Anda sudah melamar lowongan ini.'], 422);
        }

        $r->validate([
            'cover_letter' => 'nullable|string|max:3000',
            'cv' => 'required|file|mimes:pdf,doc,docx|max:' . config('hris.document_max_kb'),
            'extra.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:' . config('hris.document_max_kb'),
        ]);

        $application = DB::transaction(function () use ($r, $applicant, $vacancy) {
            $app = Application::create(['applicant_id' => $applicant->id, 'vacancy_id' => $vacancy->id, 'cover_letter' => $r->input('cover_letter')]);
            $this->storeDocument($app, $r->file('cv'), 'cv');
            foreach ($r->file('extra', []) as $file) {
                $this->storeDocument($app, $file, 'lainnya');
            }

            return $app;
        });

        return response()->json($this->shape($application->load('vacancy'), true), 201);
    }

    public function addDocument(Request $r, int $id)
    {
        $app = $this->ownApplication($r, $id);
        if (in_array($app->status, ['accepted', 'rejected'], true)) {
            return response()->json(['message' => 'Dokumen tidak dapat diubah setelah seleksi selesai.'], 422);
        }
        $r->validate([
            'type' => ['required', Rule::in(['cv', 'ijazah', 'ktp', 'lainnya'])],
            'file' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:' . config('hris.document_max_kb'),
        ]);
        $doc = $this->storeDocument($app, $r->file('file'), $r->input('type'));

        return response()->json(['id' => $doc->id, 'type' => $doc->type, 'original_name' => $doc->original_name, 'size' => $doc->size], 201);
    }

    public function deleteDocument(Request $r, int $id, int $docId)
    {
        $app = $this->ownApplication($r, $id);
        if (in_array($app->status, ['accepted', 'rejected'], true)) {
            return response()->json(['message' => 'Dokumen tidak dapat diubah setelah seleksi selesai.'], 422);
        }
        $doc = $app->documents()->findOrFail($docId);
        abort_if($app->documents()->count() <= 1, 422, 'Lamaran minimal memiliki satu dokumen.');
        Storage::disk('local')->delete($doc->path);
        $doc->delete();

        return ['message' => 'Dokumen dihapus.'];
    }

    public function downloadDocument(Request $r, int $id, int $docId)
    {
        $doc = $this->ownApplication($r, $id)->documents()->findOrFail($docId);

        return $this->download($doc);
    }

    public static function download(ApplicationDocument $doc)
    {
        abort_unless(Storage::disk('local')->exists($doc->path), 404, 'File tidak ditemukan.');

        return Storage::disk('local')->download($doc->path, $doc->original_name);
    }

    private function storeDocument(Application $app, $file, string $type): ApplicationDocument
    {
        // Disimpan di storage privat dengan nama acak; hanya metadata di database.
        $path = $file->storeAs('documents/' . $app->id, \Illuminate\Support\Str::random(40) . '.' . $file->getClientOriginalExtension(), 'local');

        return $app->documents()->create([
            'type' => $type, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'path' => $path, 'mime' => $file->getMimeType() ?? 'application/octet-stream', 'size' => $file->getSize(),
        ]);
    }
}
