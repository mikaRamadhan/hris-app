<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Jatah cuti tahunan per karyawan. Tanpa baris = memakai jatah default (config hris.leave_default_days, standar 12 hari).
        if (Schema::hasTable('leave_quotas')) {
            return; // sudah dibuat otomatis oleh aplikasi
        }
        Schema::create('leave_quotas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('year');
            $t->unsignedSmallInteger('total_days')->default(12);
            $t->timestamps();
            $t->unique(['employee_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_quotas');
    }
};
