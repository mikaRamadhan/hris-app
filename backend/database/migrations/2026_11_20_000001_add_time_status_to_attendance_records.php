<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $t) {
            $t->string('check_in_status', 15)->nullable()->after('check_in_liveness_passed'); // on_time | tolerance | late
            $t->string('check_out_status', 15)->nullable()->after('check_out_liveness_passed'); // normal | early_leave (izin)
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', fn (Blueprint $t) => $t->dropColumn(['check_in_status', 'check_out_status']));
    }
};
