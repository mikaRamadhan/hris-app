<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\OrganizationalUnit;
use Illuminate\Http\Request;

/** Data master: unit organisasi (departemen/jabatan) dan status karyawan. */
class MasterController extends Controller
{
    public function units()
    {
        return OrganizationalUnit::with('parent:id,name')->orderBy('type')->orderBy('name')->get();
    }

    private function unitRules(): array
    {
        return ['name' => 'required|string|max:120', 'type' => 'required|in:department,position',
            'parent_id' => 'nullable|exists:organizational_units,id', 'is_active' => 'boolean'];
    }

    public function storeUnit(Request $r)
    {
        return response()->json(OrganizationalUnit::create($r->validate($this->unitRules())), 201);
    }

    public function updateUnit(Request $r, int $id)
    {
        $u = OrganizationalUnit::findOrFail($id);
        $data = $r->validate($this->unitRules());
        abort_if(($data['parent_id'] ?? null) == $u->id, 422, 'Unit tidak boleh menjadi induk dirinya sendiri.');
        $u->update($data);

        return $u;
    }

    public function destroyUnit(int $id)
    {
        $u = OrganizationalUnit::findOrFail($id);
        $used = $u->children()->exists() || Employee::withTrashed()->where(fn ($q) => $q->where('department_id', $id)->orWhere('position_id', $id))->exists();
        abort_if($used, 422, 'Unit masih dipakai (memiliki sub-unit atau karyawan). Nonaktifkan saja.');
        $u->delete();

        return ['message' => 'Unit dihapus.'];
    }

    public function statuses()
    {
        return EmployeeStatus::orderBy('name')->get();
    }

    public function storeStatus(Request $r)
    {
        return response()->json(EmployeeStatus::create($r->validate(['name' => 'required|string|max:60|unique:employee_statuses,name', 'is_active' => 'boolean'])), 201);
    }

    public function updateStatus(Request $r, int $id)
    {
        $s = EmployeeStatus::findOrFail($id);
        $s->update($r->validate(['name' => 'required|string|max:60|unique:employee_statuses,name,' . $s->id, 'is_active' => 'boolean']));

        return $s;
    }

    public function destroyStatus(int $id)
    {
        $s = EmployeeStatus::findOrFail($id);
        abort_if(Employee::withTrashed()->where('employee_status_id', $id)->exists(), 422, 'Status masih dipakai karyawan.');
        $s->delete();

        return ['message' => 'Status dihapus.'];
    }
}
