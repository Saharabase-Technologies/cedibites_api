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
