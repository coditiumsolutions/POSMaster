<?php

namespace App\Models;

use App\Services\AppRoles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'users';
    protected $primaryKey = 'uid';
    public $timestamps = false;

    const CREATED_AT = 'created_date';
    const UPDATED_AT = 'updated_date';

    protected $fillable = [
        'user_name',
        'full_name',
        'password_hash',
        'mobile_no',
        'email',
        'role_name',
        'user_type',
        'terminal_name',
        'branch',
        'is_active',
        'last_login_date',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_date' => 'datetime',
        'created_date' => 'datetime',
        'updated_date' => 'datetime',
    ];

    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    public function userRoles()
    {
        return $this->hasMany(UserRole::class, 'user_uid', 'uid');
    }

    /** @return list<string> */
    public function roleNames(): array
    {
        static $hasTable = null;
        if ($hasTable === null) {
            try {
                $hasTable = Schema::hasTable('user_roles');
            } catch (\Throwable $e) {
                $hasTable = false;
            }
        }

        if ($hasTable) {
            $roles = $this->relationLoaded('userRoles')
                ? $this->userRoles->pluck('role_name')->all()
                : $this->userRoles()->pluck('role_name')->all();
            $roles = array_values(array_unique(array_filter(array_map(
                fn ($r) => AppRoles::normalizeLegacy($r) ?? $r,
                $roles
            ))));
            if ($roles !== []) {
                return $roles;
            }
        }

        $primary = AppRoles::normalizeLegacy($this->role_name);
        return $primary ? [$primary] : [];
    }

    public function isSoftware(): bool
    {
        return $this->hasRole(AppRoles::Software);
    }

    public function isAdmin(): bool
    {
        return $this->hasAnyRole([AppRoles::Admin, AppRoles::Software, 'Administrator']);
    }

    public function isManager(): bool
    {
        return $this->hasAnyRole([AppRoles::ShopOwner, 'Manager']);
    }

    public function isCashier(): bool
    {
        return $this->hasRole(AppRoles::Cashier);
    }

    public function hasRole($roles): bool
    {
        return $this->hasAnyRole($roles);
    }

    public function hasAnyRole($roles): bool
    {
        $roles = is_array($roles) ? $roles : [$roles];
        $roles = array_values(array_filter(array_map(
            fn ($r) => AppRoles::normalizeLegacy((string) $r) ?? (string) $r,
            $roles
        )));
        $mine = $this->roleNames();

        foreach ($roles as $role) {
            if (in_array($role, $mine, true)) {
                return true;
            }
        }

        return false;
    }

    public function syncRoles(array $roles): void
    {
        $roles = array_values(array_unique(array_filter(array_map(
            fn ($r) => AppRoles::normalizeLegacy((string) $r),
            $roles
        ))));
        $roles = array_values(array_intersect($roles, AppRoles::all()));

        if ($roles === []) {
            $roles = [AppRoles::Viewer];
        }

        $this->role_name = AppRoles::primary($roles);
        $this->save();

        if (!Schema::hasTable('user_roles')) {
            return;
        }

        UserRole::where('user_uid', $this->uid)->delete();
        foreach ($roles as $role) {
            UserRole::create([
                'user_uid' => $this->uid,
                'role_name' => $role,
                'created_date' => now(),
            ]);
        }

        $this->unsetRelation('userRoles');
    }
}
