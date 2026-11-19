<?php

namespace App\Services;

class AppRoles
{
    public const Software = 'Software';
    public const Admin = 'Admin';
    public const ShopOwner = 'ShopOwner';
    public const Operator = 'Operator';
    public const Cashier = 'Cashier';
    public const Viewer = 'Viewer';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::Software,
            self::Admin,
            self::ShopOwner,
            self::Operator,
            self::Cashier,
            self::Viewer,
        ];
    }

    public static function labels(): array
    {
        return [
            self::Software => 'Software',
            self::Admin => 'Admin',
            self::ShopOwner => 'Shop Owner',
            self::Operator => 'Operator',
            self::Cashier => 'Cashier',
            self::Viewer => 'Viewer',
        ];
    }

    /**
     * Primary role for users.role_name (backward compatible display / filters).
     * Prefer higher-privilege roles when multiple are selected.
     */
    public static function primary(?array $roles): string
    {
        $roles = array_values(array_unique(array_filter($roles ?? [])));
        if ($roles === []) {
            return self::Viewer;
        }

        foreach (self::all() as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return $roles[0];
    }

    public static function normalizeLegacy(?string $role): ?string
    {
        $role = trim((string) $role);
        if ($role === '') {
            return null;
        }

        $map = [
            'Administrator' => self::Admin,
            'administrator' => self::Admin,
            'Admin' => self::Admin,
            'admin' => self::Admin,
            'Manager' => self::ShopOwner,
            'manager' => self::ShopOwner,
            'ShopOwner' => self::ShopOwner,
            'Shop Owner' => self::ShopOwner,
            'Operator' => self::Operator,
            'Cashier' => self::Cashier,
            'cashier' => self::Cashier,
            'Viewer' => self::Viewer,
            'viewer' => self::Viewer,
            'Software' => self::Software,
        ];

        return $map[$role] ?? (in_array($role, self::all(), true) ? $role : null);
    }
}
