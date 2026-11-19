<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\AttendanceLocation;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $r)
    {
        $user = $r->user();

        return match ($user->role) {
            'admin' => [
                'role' => 'admin',
                'users_by_role' => User::selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role'),
                'inactive_users' => User::where('is_active', false)->count(),
                'company_configured' => filled(Setting::company()['company_name']),
                'locations' => AttendanceLocation::where('is_active', true)->count(),
            ],
            'hrd' => [
                'role' => 'hrd',
                'open_vacancies' => Vacancy::public()->count(),
                'applications' => Application::count(),
                'applications_by_status' => Application::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
                'employees_active' => Employee::where('is_active', true)->count(),
                'attendance_today' => AttendanceRecord::whereDate('attendance_date', today())->count(),
                'completed_today' => AttendanceRecord::whereDate('attendance_date', today())->where('attendance_status', 'completed')->count(),
                'locations' => AttendanceLocation::where('is_active', true)->count(),
            ],
            'pelamar' => [
                'role' => 'pelamar',
                'open_vacancies' => Vacancy::public()->count(),
                'profile_complete' => (bool) $user->applicant?->isComplete(),
                'applications' => $user->applicant
                    ? $user->applicant->applications()->with('vacancy:id,title')->latest()->take(5)->get()
                        ->map(fn ($a) => ['id' => $a->id, 'vacancy_title' => $a->vacancy?->title, 'status' => $a->publicStatus()])
                    : [],
            ],
            default => [
                'role' => 'karyawan',
                'today' => $user->employee ? AttendanceRecord::where('employee_id', $user->employee->id)->whereDate('attendance_date', today())->first() : null,
                'face_registered' => (bool) $user->employee?->faceProfile,
            ],
        };
    }
}
