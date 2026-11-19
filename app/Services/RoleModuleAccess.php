<?php

namespace App\Services;

use App\Models\User;

class RoleModuleAccess
{
    public static function canAccess(?string $controller, ?User $user): bool
    {
        if (!$user) {
            return false;
        }

        // Setup hub and Users management are Software-role only (not Admin).
        // Prevents Admins from opening Users via Modules and granting themselves Software.
        if (in_array($controller, ['Setup', 'Users'], true)) {
            return $user->hasRole(AppRoles::Software);
        }

        // Software + Admin see everything else for now.
        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return true;
        }

        if ($user->hasAnyRole([AppRoles::Admin, AppRoles::Software, 'Administrator'])) {
            return true;
        }

        $adminOnly = [
            'Configurations',
            'AuditLogs',
            'Branches',
            'Warehouses',
            'Terminals',
            'OfflineSyncTables',
        ];

        if (in_array($controller, $adminOnly, true)) {
            return false;
        }

        return true;
    }
}
