<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private function issueToken(User $user): array
    {
        $plain = Str::random(64);
        ApiToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHours(config('hris.token_hours')),
        ]);
        ApiToken::where('user_id', $user->id)->where('expires_at', '<', now())->delete();

        return ['token' => $plain, 'user' => $this->userPayload($user)];
    }

    public static function userPayload(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role];
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:150|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'role' => 'pelamar']);
            Applicant::create(['user_id' => $user->id, 'full_name' => $data['name']]);

            return $user;
        });

        return response()->json($this->issueToken($user), 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Email atau kata sandi salah.'], 422);
        }
        if (!$user->is_active) {
            return response()->json(['message' => 'Akun nonaktif. Hubungi administrator.'], 403);
        }

        return $this->issueToken($user);
    }

    public function me(Request $request)
    {
        return ['user' => $this->userPayload($request->user())];
    }

    public function logout(Request $request)
    {
        $request->attributes->get('api_token')?->delete();

        return ['message' => 'Berhasil logout.'];
    }
}
