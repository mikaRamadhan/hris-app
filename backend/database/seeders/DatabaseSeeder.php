<?php

namespace Database\Seeders;

use App\Models\AttendanceLocation;
use App\Models\EmployeeStatus;
use App\Models\OrganizationalUnit;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(['email' => 'admin@hris.test'], ['name' => 'Administrator', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $hrd = User::updateOrCreate(['email' => 'hrd@hris.test'], ['name' => 'Staf HRD', 'password' => 'password123', 'role' => 'hrd', 'is_active' => true]);

        foreach ([
            'company_name' => 'PT Contoh Sejahtera', 'company_tagline' => 'Bergabung dan tumbuh bersama kami',
            'company_about' => 'Perusahaan simulasi untuk pengembangan dan pengujian HRIS.', 'company_address' => 'Jakarta, Indonesia',
            'company_email' => 'hrd@contoh.test', 'company_phone' => '021-0000000',
        ] as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }

        foreach (['Tetap', 'Kontrak', 'Magang', 'Nonaktif'] as $s) {
            EmployeeStatus::firstOrCreate(['name' => $s], ['is_active' => $s !== 'Nonaktif']);
        }

        $deps = [];
        foreach (['Human Resources', 'Keuangan', 'Operasional', 'Teknologi Informasi'] as $d) {
            $deps[$d] = OrganizationalUnit::firstOrCreate(['name' => $d, 'type' => 'department'], ['is_active' => true]);
        }
        foreach ([['Staf Administrasi', 'Operasional'], ['Staf Keuangan', 'Keuangan'], ['Staf HR', 'Human Resources'], ['Programmer', 'Teknologi Informasi']] as [$p, $d]) {
            OrganizationalUnit::firstOrCreate(['name' => $p, 'type' => 'position'], ['parent_id' => $deps[$d]->id, 'is_active' => true]);
        }

        AttendanceLocation::firstOrCreate(['location_name' => 'Kantor Pusat'], [
            'address' => 'Ubah koordinat sesuai lokasi kantor', 'latitude' => -6.2088, 'longitude' => 106.8456,
            'radius_meters' => 150, 'is_active' => true, 'created_by' => $hrd->id,
        ]);

        Vacancy::firstOrCreate(['title' => 'Staf Administrasi'], [
            'organizational_unit_id' => $deps['Operasional']->id, 'location' => 'Jakarta',
            'description' => 'Mengelola administrasi dan arsip kantor.', 'requirements' => "Minimal D3\nMahir Microsoft Office\nTeliti dan komunikatif",
            'quota' => 2, 'open_at' => now()->subDay()->toDateString(), 'close_at' => now()->addMonth()->toDateString(), 'status' => 'open', 'created_by' => $hrd->id,
        ]);
        Vacancy::firstOrCreate(['title' => 'Programmer Web'], [
            'organizational_unit_id' => $deps['Teknologi Informasi']->id, 'location' => 'Jakarta',
            'description' => 'Mengembangkan dan memelihara aplikasi web perusahaan.', 'requirements' => "Minimal S1 Informatika\nMenguasai PHP/Laravel dan React\nMemahami MySQL",
            'quota' => 1, 'open_at' => now()->subDay()->toDateString(), 'close_at' => now()->addWeeks(3)->toDateString(), 'status' => 'open', 'created_by' => $hrd->id,
        ]);
    }
}
