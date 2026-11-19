<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->dateTime('last_used_at')->nullable();
            $t->dateTime('expires_at');
            $t->timestamps();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
            $t->timestamps();
        });

        Schema::create('organizational_units', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('type', 15); // department | position
            $t->foreignId('parent_id')->nullable()->constrained('organizational_units')->restrictOnDelete();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('employee_statuses', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('applicants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->string('full_name');
            $t->string('phone', 25)->nullable();
            $t->text('address')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('gender', 10)->nullable();
            $t->string('education', 100)->nullable();
            $t->string('institution')->nullable();
            $t->text('skills')->nullable();
            $t->text('summary')->nullable();
            $t->timestamps();
        });

        Schema::create('vacancies', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->foreignId('organizational_unit_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            $t->string('location')->nullable();
            $t->text('description');
            $t->text('requirements');
            $t->unsignedInteger('quota')->default(1);
            $t->date('open_at');
            $t->date('close_at');
            $t->string('status', 10)->default('draft'); // draft | open | closed
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('applicant_id')->constrained()->restrictOnDelete();
            $t->foreignId('vacancy_id')->constrained()->restrictOnDelete();
            $t->text('cover_letter')->nullable();
            $t->string('status', 15)->default('submitted'); // submitted|reviewing|interview|accepted|rejected
            $t->dateTime('announced_at')->nullable();
            $t->timestamps();
            $t->unique(['applicant_id', 'vacancy_id']);
            $t->index('status');
        });

        Schema::create('application_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->constrained()->cascadeOnDelete();
            $t->string('type', 20); // cv | ijazah | ktp | lainnya
            $t->string('original_name');
            $t->string('path');
            $t->string('mime', 100);
            $t->unsignedInteger('size');
            $t->timestamps();
        });

        Schema::create('selection_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->constrained()->restrictOnDelete();
            $t->string('stage', 15); // screening | interview | final
            $t->string('result', 10); // pass | fail | pending
            $t->text('notes')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('is_announced')->default(false);
            $t->timestamps();
        });

        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->foreignId('applicant_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->string('employee_number', 30)->unique();
            $t->string('full_name');
            $t->string('email');
            $t->string('phone', 25)->nullable();
            $t->text('address')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('gender', 10)->nullable();
            $t->foreignId('department_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            $t->foreignId('position_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            $t->foreignId('employee_status_id')->nullable()->constrained('employee_statuses')->nullOnDelete();
            $t->date('join_date');
            $t->date('end_date')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('attendance_locations', function (Blueprint $t) {
            $t->id();
            $t->string('location_name');
            $t->text('address')->nullable();
            $t->decimal('latitude', 10, 7);
            $t->decimal('longitude', 10, 7);
            $t->unsignedInteger('radius_meters')->default(100);
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('employee_face_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $t->longText('face_embedding'); // terenkripsi (cast encrypted:array)
            $t->string('model_version', 50)->default('face-api-128d');
            $t->dateTime('consented_at');
            $t->dateTime('registered_at');
            $t->dateTime('last_updated_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('attendance_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('attendance_location_id')->nullable()->constrained('attendance_locations')->nullOnDelete();
            $t->date('attendance_date');
            $t->dateTime('check_in_at')->nullable();
            $t->decimal('check_in_latitude', 10, 7)->nullable();
            $t->decimal('check_in_longitude', 10, 7)->nullable();
            $t->unsignedInteger('check_in_distance_meters')->nullable();
            $t->boolean('check_in_location_verified')->default(false);
            $t->boolean('check_in_face_verified')->default(false);
            $t->boolean('check_in_liveness_passed')->default(false);
            $t->dateTime('check_out_at')->nullable();
            $t->decimal('check_out_latitude', 10, 7)->nullable();
            $t->decimal('check_out_longitude', 10, 7)->nullable();
            $t->unsignedInteger('check_out_distance_meters')->nullable();
            $t->boolean('check_out_location_verified')->default(false);
            $t->boolean('check_out_face_verified')->default(false);
            $t->boolean('check_out_liveness_passed')->default(false);
            $t->string('attendance_status', 15)->default('checked_in'); // checked_in | completed
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'attendance_date']);
        });

        // Percobaan absensi (berhasil/gagal) untuk audit; hanya record berhasil yang dihitung sebagai kehadiran.
        Schema::create('attendance_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('type', 10); // check_in | check_out
            $t->foreignId('attendance_location_id')->nullable()->constrained('attendance_locations')->nullOnDelete();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->unsignedInteger('distance_meters')->nullable();
            $t->boolean('location_ok')->default(false);
            $t->boolean('face_ok')->default(false);
            $t->boolean('liveness_ok')->default(false);
            $t->boolean('success')->default(false);
            $t->string('reason')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 60);
            $t->string('subject_type', 60)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('details')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();
            $t->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'attendance_attempts', 'attendance_records', 'employee_face_profiles', 'attendance_locations',
            'employees', 'selection_results', 'application_documents', 'applications', 'vacancies', 'applicants',
            'employee_statuses', 'organizational_units', 'settings', 'api_tokens'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
