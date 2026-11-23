<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('yard');
            $table->date('start');
            $table->date('end');
            $table->json('draft');
            $table->unique(['user_id', 'code']);
        });
        Schema::create('baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('structure');
            $table->unique(['project_id', 'revision']);
        });
        Schema::create('report_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('baseline_id')->constrained()->cascadeOnDelete();
            $table->date('start');
            $table->date('end');
            $table->decimal('target', 8, 4)->nullable();
            $table->timestamp('closed_at')->nullable();
        });
        Schema::create('measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('report_periods')->cascadeOnDelete();
            $table->uuid('item');
            $table->uuid('stage');
            $table->decimal('value', 8, 4);
            $table->unique(['period_id', 'item', 'stage']);
        });
    }

    public function down(): void
    {
        foreach (['measurements', 'report_periods', 'baselines', 'projects'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
