<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeFaceProfile extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['face_embedding', 'photo_path'];
    protected $casts = [
        'face_embedding' => 'encrypted:array', // template biometrik dienkripsi (APP_KEY)
        'consented_at' => 'datetime',
        'registered_at' => 'datetime',
        'last_updated_at' => 'datetime',
        'is_active' => 'boolean',
    ];
}
