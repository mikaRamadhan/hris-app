<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ApplicantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeAreaController;
use App\Http\Controllers\Hrd;
use App\Http\Controllers\PublicController;
use Illuminate\Support\Facades\Route;

// Publik (pengunjung)
Route::get('public/company', [PublicController::class, 'company']);
Route::get('public/vacancies', [PublicController::class, 'vacancies']);
Route::get('public/vacancies/{id}', [PublicController::class, 'vacancy']);

Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth.api')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('dashboard', DashboardController::class);

    // Pelamar
    Route::middleware('role:pelamar')->prefix('applicant')->group(function () {
        Route::get('profile', [ApplicantController::class, 'profile']);
        Route::put('profile', [ApplicantController::class, 'updateProfile']);
        Route::get('applications', [ApplicantController::class, 'applications']);
        Route::get('applications/{id}', [ApplicantController::class, 'application']);
        Route::post('vacancies/{id}/apply', [ApplicantController::class, 'apply']);
        Route::post('applications/{id}/documents', [ApplicantController::class, 'addDocument']);
        Route::get('applications/{id}/documents/{docId}', [ApplicantController::class, 'downloadDocument']);
        Route::delete('applications/{id}/documents/{docId}', [ApplicantController::class, 'deleteDocument']);
    });

    // HRD
    Route::middleware('role:hrd')->prefix('hrd')->group(function () {
        Route::apiResource('vacancies', Hrd\VacancyController::class);
        Route::get('applications', [Hrd\ApplicationController::class, 'index']);
        Route::get('applications/{id}', [Hrd\ApplicationController::class, 'show']);
        Route::get('applications/{id}/documents/{docId}', [Hrd\ApplicationController::class, 'document']);
        Route::post('applications/{id}/results', [Hrd\ApplicationController::class, 'addResult']);
        Route::post('applications/{id}/announce', [Hrd\ApplicationController::class, 'announce']);
        Route::post('applications/{id}/convert', [Hrd\ApplicationController::class, 'convert']);

        Route::get('employees/export', [Hrd\EmployeeController::class, 'export']);
        Route::get('employees/{id}/face-photo', [Hrd\EmployeeController::class, 'facePhoto']);
        Route::apiResource('employees', Hrd\EmployeeController::class);

        Route::get('units', [Hrd\MasterController::class, 'units']);
        Route::post('units', [Hrd\MasterController::class, 'storeUnit']);
        Route::put('units/{id}', [Hrd\MasterController::class, 'updateUnit']);
        Route::delete('units/{id}', [Hrd\MasterController::class, 'destroyUnit']);
        Route::get('employee-statuses', [Hrd\MasterController::class, 'statuses']);
        Route::post('employee-statuses', [Hrd\MasterController::class, 'storeStatus']);
        Route::put('employee-statuses/{id}', [Hrd\MasterController::class, 'updateStatus']);
        Route::delete('employee-statuses/{id}', [Hrd\MasterController::class, 'destroyStatus']);

        Route::get('locations', [Hrd\AttendanceController::class, 'locations']);
        Route::post('locations', [Hrd\AttendanceController::class, 'storeLocation']);
        Route::put('locations/{id}', [Hrd\AttendanceController::class, 'updateLocation']);
        Route::delete('locations/{id}', [Hrd\AttendanceController::class, 'destroyLocation']);
        Route::get('attendance', [Hrd\AttendanceController::class, 'records']);
        Route::get('attendance/export', [Hrd\AttendanceController::class, 'export']);
        Route::get('attendance/attempts', [Hrd\AttendanceController::class, 'attempts']);
        Route::get('attendance/{id}/photo/{which}', [Hrd\AttendanceController::class, 'photo']);
    });

    // Admin
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::put('users/{id}', [UserController::class, 'update']);
        Route::get('company', [UserController::class, 'company']);
        Route::put('company', [UserController::class, 'updateCompany']);
        Route::get('audit-logs', [UserController::class, 'auditLogs']);
    });

    // Karyawan
    Route::middleware('role:karyawan')->prefix('employee')->group(function () {
        Route::get('me', [EmployeeAreaController::class, 'me']);
        Route::post('face', [EmployeeAreaController::class, 'registerFace'])->middleware('throttle:10,1');
        Route::delete('face', [EmployeeAreaController::class, 'deleteFace']);
        Route::post('attendance/{type}', [EmployeeAreaController::class, 'attend'])->middleware('throttle:20,1');
        Route::get('attendance', [EmployeeAreaController::class, 'history']);
        Route::get('attendance/{id}/photo/{which}', [EmployeeAreaController::class, 'attendancePhoto']);
        Route::get('face/photo', [EmployeeAreaController::class, 'facePhoto']);
    });
});
