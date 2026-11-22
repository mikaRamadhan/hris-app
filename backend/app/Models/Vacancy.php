<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vacancy extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];
    protected $casts = ['open_at' => 'date:Y-m-d', 'close_at' => 'date:Y-m-d', 'cv_keywords' => 'array'];

    public function unit()
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organizational_unit_id');
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function acceptedCount(): int
    {
        return $this->applications()->where('status', 'accepted')->count();
    }

    /** Lowongan dapat dilamar: status open, dalam periode, dan kuota belum terpenuhi. */
    public function isOpen(): bool
    {
        return $this->status === 'open'
            && $this->open_at->startOfDay()->lte(now())
            && $this->close_at->endOfDay()->gte(now())
            && $this->acceptedCount() < $this->quota;
    }

    public function scopePublic($q)
    {
        return $q->where('status', 'open')->whereDate('open_at', '<=', today())->whereDate('close_at', '>=', today());
    }
}
