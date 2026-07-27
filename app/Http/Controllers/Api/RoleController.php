<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Get all roles with their permissions.
     */
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->get()->map(function ($role) {
            return [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $this->formatRoleDisplayName($role->name),
                'permissions' => $role->permissions->pluck('name')->toArray(),
            ];
        });

        return response()->success($roles, 'Roles retrieved successfully.');
    }

    /**
     * Get all permissions.
     */
    public function permissions(): JsonResponse
    {
        $permissions = Permission::all()->map(function ($permission) {
            return [
                'id' => $permission->id,
                'name' => $permission->name,
                'display_name' => $this->formatPermissionDisplayName($permission->name),
                'description' => $this->getPermissionDescription($permission->name),
            ];
        });

        return response()->success($permissions, 'Permissions retrieved successfully.');
    }

    /**
     * Format role name for display.
     */
    private function formatRoleDisplayName(string $roleName): string
    {
        $displayNames = [
            'tech_admin' => 'Tech Admin',
            'admin' => 'Admin',
            'branch_partner' => 'Branch Partner',
            'manager' => 'Branch Manager',
            'call_center' => 'Call Center',
            'sales_staff' => 'Sales Staff',
            'kitchen' => 'Kitchen Staff',
            'rider' => 'Delivery Rider',
        ];

        return $displayNames[$roleName] ?? ucwords(str_replace('_', ' ', $roleName));
    }

    /**
     * Format permission name for display.
     */
    private function formatPermissionDisplayName(string $permissionName): string
    {
        $displayNames = [
            'view_orders' => 'Can View Orders',
            'create_orders' => 'Can Place Orders',
            'update_orders' => 'Can Advance Orders',
            'delete_orders' => 'Can Cancel Orders',
            'view_menu' => 'Can View Menu',
            'manage_menu' => 'Can Manage Menu',
            'view_branches' => 'Can View Branches',
            'manage_branches' => 'Can Manage Branches',
            'branch.operate' => 'Can Open & Close Own Branch',
            'menu.availability.manage' => 'Can Set Item Availability',
            'view_customers' => 'Can View Customers',
            'manage_customers' => 'Can Manage Customers',
            'view_employees' => 'Can View Staff',
            'manage_employees' => 'Can Manage Staff',
            'employee.notes.manage' => 'Can Keep Notes on Staff',
            'view_analytics' => 'Can View Reports',
            'view_activity_log' => 'Can View Activity Log',
            // Portal access
            'access_admin_panel' => 'Access Admin Panel',
            'access_manager_portal' => 'Access Manager Portal',
            'access_sales_portal' => 'Access Sales Portal',
            'access_partner_portal' => 'Access Partner Portal',
            'access_pos' => 'Access POS Terminal',
            'access_kitchen' => 'Access Kitchen Display',
            'access_order_manager' => 'Access Order Manager',
            // Feature flags
            'manage_shifts' => 'Manage Shifts',
            'manage_settings' => 'Manage Settings',
            'view_my_shifts' => 'View My Shifts',
            'view_my_sales' => 'View My Sales',
        ];

        return $displayNames[$permissionName] ?? ucwords(str_replace('_', ' ', $permissionName));
    }

    /**
     * Get permission description.
     */
    private function getPermissionDescription(string $permissionName): string
    {
        $descriptions = [
            'view_orders' => 'View orders and order history',
            'create_orders' => 'Create new orders via call center or staff portal',
            'update_orders' => 'Move orders through statuses (accept, start, complete)',
            'delete_orders' => 'Cancel or delete orders',
            'view_menu' => 'View menu items and categories',
            'manage_menu' => 'Add, edit, or remove menu items and categories',
            'view_branches' => 'View branch information',
            'manage_branches' => 'Create and delete branches',
            'branch.operate' => 'Open, close and set extended access for your own branch',
            'menu.availability.manage' => 'Mark a dish available or sold out at your own branch (not the price)',
            'view_customers' => 'View customer information',
            'manage_customers' => 'Create, edit, or manage customer accounts',
            'view_employees' => 'View staff accounts and information',
            'manage_employees' => 'Create, edit, or suspend staff accounts',
            'employee.notes.manage' => 'Keep and edit your own notes on staff at your branch',
            'view_analytics' => 'Access to sales analytics and branch performance',
            'view_activity_log' => 'View system activity and audit logs',
            // Portal access
            'access_admin_panel' => 'Log in to the admin panel',
            'access_manager_portal' => 'Log in to the manager portal',
            'access_sales_portal' => 'Log in to the staff sales portal',
            'access_partner_portal' => 'Log in to the branch partner portal',
            'access_pos' => 'Log in to the POS terminal with a PIN',
            'access_kitchen' => 'Log in to the kitchen display',
            'access_order_manager' => 'Log in to the order manager display',
            // Feature flags
            'manage_shifts' => 'View and manage staff shifts for the branch',
            'manage_settings' => 'Configure branch settings',
            'view_my_shifts' => 'View personal shift history and summaries',
            'view_my_sales' => 'View personal sales history and totals',
        ];

        return $descriptions[$permissionName] ?? 'Permission to '.str_replace('_', ' ', $permissionName);
    }
}
