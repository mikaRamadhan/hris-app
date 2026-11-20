<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_face_profiles', function (Blueprint $t) {
            $t->string('photo_path')->nullable()->after('model_version');
        });
        Schema::table('attendance_records', function (Blueprint $t) {
            $t->string('check_in_photo')->nullable();
            $t->string('check_in_reason_type', 20)->nullable();
            $t->string('check_in_reason_note', 300)->nullable();
            $t->string('check_out_photo')->nullable();
            $t->string('check_out_reason_type', 20)->nullable();
            $t->string('check_out_reason_note', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', fn (Blueprint $t) => $t->dropColumn(['check_in_photo', 'check_in_reason_type', 'check_in_reason_note', 'check_out_photo', 'check_out_reason_type', 'check_out_reason_note']));
        Schema::table('employee_face_profiles', fn (Blueprint $t) => $t->dropColumn('photo_path'));
    }
};
