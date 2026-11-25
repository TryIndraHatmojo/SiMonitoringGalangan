<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engineering_estimate_lines', function (Blueprint $table) {
            $table->uuid('wbs_group_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('engineering_estimate_lines', function (Blueprint $table) {
            $table->dropColumn('wbs_group_id');
        });
    }
};
