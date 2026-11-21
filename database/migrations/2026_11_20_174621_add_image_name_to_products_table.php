<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'image_name')) {
                $table->string('image_name', 255)->nullable()->after('image_path');
            }
            if (!Schema::hasColumn('products', 'image_path')) {
                $table->string('image_path', 500)->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'image_name')) {
                $table->dropColumn('image_name');
            }
        });
    }
};
