<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Profil akun HRD & karyawan: foto profil, jabatan, departemen. */
class ProfileController extends Controller
{
    private function payload(Request $r): array
    {
        $u = $r->user();
        $e = $u->role === 'hrd' ? Employee::ensureForUser($u) : $u->employee;
        $e?->load(['department:id,name', 'position:id,name', 'status:id,name']);

        return [
            'user' => AuthController::userPayload($u),
            'employee' => $e ? [
                'employee_number' => $e->employee_number, 'full_name' => $e->full_name, 'email' => $e->email, 'phone' => $e->phone,
                'department' => $e->department?->name, 'position' => $e->position?->name, 'status' => $e->status?->name,
                'join_date' => $e->join_date?->toDateString(),
            ] : null,
        ];
    }

    public function show(Request $r)
    {
        return $this->payload($r);
    }

    public function updatePhoto(Request $r)
    {
        $r->validate(['photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048']);
        $u = $r->user();
        $file = $r->file('photo');
        $name = Str::random(40) . '.' . ($file->extension() ?: 'jpg');

        $old = $u->avatar_path;
        Storage::disk('local')->putFileAs('avatars', $file, $name);
        $u->forceFill(['avatar_path' => 'avatars/' . $name])->save();
        if ($old) {
            Storage::disk('local')->delete($old);
        }
        AuditLog::record($u, 'profile.photo_update');

        return $this->payload($r);
    }

    public function deletePhoto(Request $r)
    {
        $u = $r->user();
        if ($u->avatar_path) {
            Storage::disk('local')->delete($u->avatar_path);
            $u->forceFill(['avatar_path' => null])->save();
            AuditLog::record($u, 'profile.photo_delete');
        }

        return $this->payload($r);
    }

    /** Foto disajikan lewat API (nama berkas acak) agar <img> dapat memuatnya tanpa token dan tanpa storage:link. */
    public function avatar(string $file)
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}\.(jpg|jpeg|png|webp)$/', $file) === 1, 404);
        abort_unless(Storage::disk('local')->exists('avatars/' . $file), 404);

        return Storage::disk('local')->response('avatars/' . $file, null, ['Cache-Control' => 'private, max-age=86400']);
    }
}
