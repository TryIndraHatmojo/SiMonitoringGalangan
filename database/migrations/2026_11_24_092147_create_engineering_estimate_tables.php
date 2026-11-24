<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineering_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->decimal('exchange_rate_idr_per_usd', 16, 6)->nullable();
            $table->date('exchange_rate_date')->nullable();
            $table->string('exchange_rate_source')->nullable();
            $table->boolean('exchange_rate_is_assumption')->default(true);
            $table->string('source_reference')->nullable();
            $table->decimal('bac_idr', 24, 2)->nullable();
            $table->unique(['project_id', 'revision']);
        });

        Schema::create('engineering_estimate_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engineering_estimate_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('description');
            $table->decimal('quantity', 16, 6)->nullable();
            $table->string('unit', 50)->nullable();
            $table->decimal('local_price_idr', 20, 6)->nullable();
            $table->boolean('local_price_is_lump_sum')->default(false);
            $table->decimal('usd_unit_price', 20, 6)->nullable();
            $table->uuid('wbs_item_id')->nullable();
            $table->string('source_reference')->nullable();
            $table->string('source_work_group')->nullable();
            $table->unique(['engineering_estimate_id', 'position'], 'engineering_estimate_lines_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineering_estimate_lines');
        Schema::dropIfExists('engineering_estimates');
    }
};
