<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_roles')) {
            Schema::create('user_roles', function (Blueprint $table) {
                $table->increments('uid');
                $table->unsignedInteger('user_uid');
                $table->string('role_name', 50);
                $table->timestamp('created_date')->useCurrent();
                $table->unique(['user_uid', 'role_name'], 'user_roles_user_role_unique');
                $table->index('user_uid');
                $table->index('role_name');
            });
        }

        // Seed existing single role_name into user_roles (mapped to new names where needed).
        if (Schema::hasTable('users') && Schema::hasTable('user_roles')) {
            $map = [
                'Administrator' => 'Admin',
                'administrator' => 'Admin',
                'Admin' => 'Admin',
                'Manager' => 'ShopOwner',
                'manager' => 'ShopOwner',
                'ShopOwner' => 'ShopOwner',
                'Cashier' => 'Cashier',
                'cashier' => 'Cashier',
                'Viewer' => 'Viewer',
                'viewer' => 'Viewer',
                'Operator' => 'Operator',
                'Software' => 'Software',
            ];

            $users = DB::table('users')->select('uid', 'role_name')->get();
            foreach ($users as $user) {
                $raw = trim((string) $user->role_name);
                if ($raw === '') {
                    continue;
                }
                $role = $map[$raw] ?? $raw;
                $exists = DB::table('user_roles')
                    ->where('user_uid', $user->uid)
                    ->where('role_name', $role)
                    ->exists();
                if (!$exists) {
                    DB::table('user_roles')->insert([
                        'user_uid' => $user->uid,
                        'role_name' => $role,
                        'created_date' => now(),
                    ]);
                }

                // Keep users.role_name as primary display role using new naming.
                if ($raw !== $role) {
                    DB::table('users')->where('uid', $user->uid)->update(['role_name' => $role]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
