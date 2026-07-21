<?php

namespace Database\Seeders;

use App\Enums\EmployeeStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $this->createAdmin();
        $this->createWarehouseManager();
        $this->createPurchasingClerk();
        $this->createBranchStaff();
    }

    /**
     * Deterministic branch-level accounts for end-to-end IMS testing.
     *
     * Unlike the accounts above (synced to every branch), each of these is
     * pinned to a SINGLE branch — that is what makes the location scope
     * observable: the Ashaiman manager must not see Spintex's stock.
     */
    private function createBranchStaff(): void
    {
        $pins = [
            ['Ashaiman', 'bm.ashaiman@cedibites.test', 'Ama Boateng', 'BRM0001', Role::Manager, '+233241000020'],
            ['Spintex', 'bm.spintex@cedibites.test', 'Kwesi Owusu', 'BRM0002', Role::Manager, '+233241000021'],
            ['Ashaiman', 'sales.ashaiman@cedibites.test', 'Adjoa Nyarko', 'SLS0001', Role::SalesStaff, '+233241000022'],
        ];

        foreach ($pins as [$branchName, $email, $name, $employeeNo, $role, $phone]) {
            $branch = Branch::where('name', $branchName)->first();

            if (! $branch) {
                continue;
            }

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'username' => str($email)->before('@')->replace('.', '_')->toString(),
                    'phone' => $phone,
                    'password' => bcrypt('password'),
                ]
            );

            $user->syncRoles([$role->value]);

            $emp = Employee::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'employee_no' => $employeeNo,
                    'status' => EmployeeStatus::Active,
                    'hire_date' => now()->subMonths(4),
                    'performance_rating' => null,
                ]
            );

            // sync(), not attach() — one branch, and re-running must not accumulate.
            $emp->branches()->sync([$branch->id]);
        }
    }

    private function createAdmin(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@cedibites.com'],
            [
                'name' => 'Platform Admin',
                'username' => 'admin',
                'phone' => '+233241000000',
                'password' => bcrypt('password'),
            ]
        );

        $admin->syncRoles([Role::Admin->value]);

        $emp = Employee::updateOrCreate(
            ['user_id' => $admin->id],
            [
                'employee_no' => 'ADM0001',
                'status' => EmployeeStatus::Active,
                'hire_date' => now()->subYear(),
                'performance_rating' => null,
            ]
        );
        $emp->branches()->sync(Branch::all());
    }

    private function createWarehouseManager(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'warehouse@cedibites.test'],
            [
                'name' => 'Warehouse Manager',
                'username' => 'warehouse',
                'phone' => '+233241000010',
                'password' => bcrypt('password'),
            ]
        );

        $user->syncRoles([Role::WarehouseManager->value]);

        $emp = Employee::updateOrCreate(
            ['user_id' => $user->id],
            [
                'employee_no' => 'WHM0001',
                'status' => EmployeeStatus::Active,
                'hire_date' => now()->subMonths(6),
                'performance_rating' => null,
            ]
        );
        $emp->branches()->sync(Branch::all());
    }

    private function createPurchasingClerk(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'purchasing@cedibites.test'],
            [
                'name' => 'Purchasing Clerk',
                'username' => 'purchasing',
                'phone' => '+233241000011',
                'password' => bcrypt('password'),
            ]
        );

        $user->syncRoles([Role::PurchasingClerk->value]);

        $emp = Employee::updateOrCreate(
            ['user_id' => $user->id],
            [
                'employee_no' => 'PCK0001',
                'status' => EmployeeStatus::Active,
                'hire_date' => now()->subMonths(3),
                'performance_rating' => null,
            ]
        );
        $emp->branches()->sync(Branch::all());
    }
}
