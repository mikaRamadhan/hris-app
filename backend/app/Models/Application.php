<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    public const STATUSES = ['submitted', 'reviewing', 'interview', 'accepted', 'rejected'];

    protected $guarded = ['id'];
    protected $attributes = ['status' => 'submitted'];
    protected $casts = ['announced_at' => 'datetime'];

    public function applicant()
    {
        return $this->belongsTo(Applicant::class);
    }

    public function vacancy()
    {
        return $this->belongsTo(Vacancy::class)->withTrashed();
    }

    public function documents()
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function results()
    {
        return $this->hasMany(SelectionResult::class)->latest('id');
    }

    /** Status yang boleh dilihat pelamar: hasil akhir baru tampil setelah diumumkan. */
    public function publicStatus(): string
    {
        if (in_array($this->status, ['accepted', 'rejected'], true) && !$this->announced_at) {
            return 'reviewing';
        }

        return $this->status;
    }
}
