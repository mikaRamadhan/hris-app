<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Vacancy;

class PublicController extends Controller
{
    public function company()
    {
        return Setting::company();
    }

    public function vacancies()
    {
        return Vacancy::public()->with('unit:id,name')->latest()->get()
            ->filter(fn (Vacancy $v) => $v->isOpen())->values()
            ->map(fn (Vacancy $v) => $this->shape($v));
    }

    public function vacancy(int $id)
    {
        $v = Vacancy::public()->with('unit:id,name')->findOrFail($id);
        abort_unless($v->isOpen(), 404, 'Lowongan tidak tersedia.');

        return $this->shape($v);
    }

    private function shape(Vacancy $v): array
    {
        return [
            'id' => $v->id, 'title' => $v->title, 'unit' => $v->unit?->name, 'location' => $v->location,
            'description' => $v->description, 'requirements' => $v->requirements, 'quota' => $v->quota,
            'open_at' => $v->open_at->toDateString(), 'close_at' => $v->close_at->toDateString(),
        ];
    }
}
