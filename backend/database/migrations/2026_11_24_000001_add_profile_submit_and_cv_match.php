<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Foto profil (HRD & karyawan)
        Schema::table('users', function (Blueprint $t) {
            $t->string('avatar_path')->nullable()->after('is_active');
        });

        // Absensi: setelah check-out, karyawan/HRD menekan "Selesaikan absensi hari ini" (submit).
        // Kolom terpisah agar status & rekap absensi yang sudah ada tidak berubah.
        Schema::table('attendance_records', function (Blueprint $t) {
            $t->dateTime('submitted_at')->nullable();
        });
        // Data lama yang sudah check-out dianggap sudah disubmit
        DB::table('attendance_records')->whereNotNull('check_out_at')->whereNull('submitted_at')
            ->update(['submitted_at' => DB::raw('check_out_at')]);

        // Kualifikasi CV berdasarkan keyword yang dipilih HRD
        Schema::table('vacancies', function (Blueprint $t) {
            $t->json('cv_keywords')->nullable(); // [{keyword, weight}]
        });
        Schema::table('application_documents', function (Blueprint $t) {
            $t->longText('extracted_text')->nullable(); // null = belum diekstrak
        });
        Schema::table('applications', function (Blueprint $t) {
            $t->decimal('cv_score', 5, 2)->nullable()->index();
            $t->json('cv_match')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $t) => $t->dropColumn(['cv_score', 'cv_match']));
        Schema::table('application_documents', fn (Blueprint $t) => $t->dropColumn('extracted_text'));
        Schema::table('vacancies', fn (Blueprint $t) => $t->dropColumn('cv_keywords'));
        Schema::table('attendance_records', fn (Blueprint $t) => $t->dropColumn('submitted_at'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('avatar_path'));
    }
};
