<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('leave_requests')) {
            return; // sudah dibuat otomatis oleh aplikasi
        }
        Schema::create('leave_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('type', 20);
            $t->date('start_date');
            $t->date('end_date');
            $t->unsignedSmallInteger('days');
            $t->text('reason');
            $t->string('attachment_path')->nullable();
            $t->string('attachment_name')->nullable();
            $t->string('status', 15)->default('pending'); // pending | approved | rejected | cancelled
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
