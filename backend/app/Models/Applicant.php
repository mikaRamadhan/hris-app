<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Applicant extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['birth_date' => 'date:Y-m-d'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function isComplete(): bool
    {
        return filled($this->phone) && filled($this->address) && filled($this->education);
    }
}
