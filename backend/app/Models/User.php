<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    public const ROLES = ['admin', 'hrd', 'pelamar', 'karyawan'];

    protected $fillable = ['name', 'email', 'password', 'role', 'is_active', 'avatar_path'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_active' => 'boolean'];
    }

    public function applicant()
    {
        return $this->hasOne(Applicant::class);
    }

    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? '/api/avatars/' . basename($this->avatar_path) : null;
    }

    public function apiTokens()
    {
        return $this->hasMany(ApiToken::class);
    }
}
