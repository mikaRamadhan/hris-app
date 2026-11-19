<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    public const ROLES = ['admin', 'hrd', 'pelamar', 'karyawan'];

    protected $fillable = ['name', 'email', 'password', 'role', 'is_active'];

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

    public function apiTokens()
    {
        return $this->hasMany(ApiToken::class);
    }
}
