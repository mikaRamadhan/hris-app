<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    public static function company(): array
    {
        $keys = ['company_name', 'company_tagline', 'company_about', 'company_address', 'company_email', 'company_phone'];
        $rows = static::whereIn('key', $keys)->pluck('value', 'key');

        return collect($keys)->mapWithKeys(fn ($k) => [$k => $rows[$k] ?? ''])->all();
    }
}
