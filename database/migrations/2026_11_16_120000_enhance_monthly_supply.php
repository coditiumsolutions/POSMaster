<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers') && !Schema::hasColumn('customers', 'customer_type')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('customer_type', 20)->default('regular')->after('is_active');
                $table->index('customer_type');
            });
        }

        if (Schema::hasTable('customer_monthly_supplies')) {
            Schema::table('customer_monthly_supplies', function (Blueprint $table) {
                if (!Schema::hasColumn('customer_monthly_supplies', 'is_active')) {
                    $table->boolean('is_active')->default(true)->after('delivery_charges');
                }
            });

            // day_of_month = 0 means every day (daily standing order)
            try {
                DB::statement('ALTER TABLE customer_monthly_supplies MODIFY day_of_month INT NOT NULL DEFAULT 0');
            } catch (\Throwable $e) {
                // ignore if already compatible
            }
        }

        if (Schema::hasTable('customer_monthly_supply_overrides')) {
            Schema::table('customer_monthly_supply_overrides', function (Blueprint $table) {
                if (!Schema::hasColumn('customer_monthly_supply_overrides', 'is_skipped')) {
                    $table->boolean('is_skipped')->default(false)->after('rate');
                }
                if (!Schema::hasColumn('customer_monthly_supply_overrides', 'remarks')) {
                    $table->string('remarks', 255)->nullable()->after('is_skipped');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'customer_type')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('customer_type');
            });
        }

        if (Schema::hasTable('customer_monthly_supplies') && Schema::hasColumn('customer_monthly_supplies', 'is_active')) {
            Schema::table('customer_monthly_supplies', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }

        if (Schema::hasTable('customer_monthly_supply_overrides')) {
            Schema::table('customer_monthly_supply_overrides', function (Blueprint $table) {
                if (Schema::hasColumn('customer_monthly_supply_overrides', 'is_skipped')) {
                    $table->dropColumn('is_skipped');
                }
                if (Schema::hasColumn('customer_monthly_supply_overrides', 'remarks')) {
                    $table->dropColumn('remarks');
                }
            });
        }
    }
};
