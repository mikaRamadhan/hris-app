<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $r)
    {
        return User::select('id', 'name', 'email', 'role', 'is_active', 'created_at')
            ->when($r->role, fn ($q, $v) => $q->where('role', $v))
            ->when($r->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")))
            ->orderBy('name')->paginate(15);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:150|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in(User::ROLES)],
        ]);
        $u = User::create($data + ['is_active' => true]);
        AuditLog::record($r->user(), 'user.create', $u, ['role' => $u->role]);

        return response()->json($u->only('id', 'name', 'email', 'role', 'is_active'), 201);
    }

    public function update(Request $r, int $id)
    {
        $u = User::findOrFail($id);
        $data = $r->validate([
            'name' => 'required|string|max:100',
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($u->id)],
            'password' => 'nullable|string|min:8',
            'role' => ['required', Rule::in(User::ROLES)],
            'is_active' => 'required|boolean',
        ]);
        if ($u->id === $r->user()->id && ($data['role'] !== $u->role || !$data['is_active'])) {
            return response()->json(['message' => 'Anda tidak dapat mengubah role atau menonaktifkan akun sendiri.'], 422);
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $oldRole = $u->role;
        $u->update($data);
        if (!$u->is_active || isset($data['password']) || $oldRole !== $u->role) {
            ApiToken::where('user_id', $u->id)->delete(); // paksa login ulang
        }
        AuditLog::record($r->user(), 'user.update', $u, ['old_role' => $oldRole, 'role' => $u->role, 'is_active' => $u->is_active]);

        return $u->only('id', 'name', 'email', 'role', 'is_active');
    }

    public function company()
    {
        return Setting::company();
    }

    public function updateCompany(Request $r)
    {
        $data = $r->validate([
            'company_name' => 'required|string|max:150',
            'company_tagline' => 'nullable|string|max:200',
            'company_about' => 'nullable|string|max:3000',
            'company_address' => 'nullable|string|max:500',
            'company_email' => 'nullable|email|max:150',
            'company_phone' => 'nullable|string|max:30',
        ]);
        foreach ($data as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        AuditLog::record($r->user(), 'company.update');

        return Setting::company();
    }

    public function auditLogs()
    {
        return AuditLog::with('user:id,name')->latest()->paginate(20);
    }
}
