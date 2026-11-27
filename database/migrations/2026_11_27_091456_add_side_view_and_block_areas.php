<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('side_view_path')->nullable();
            $table->unsignedInteger('side_view_width')->nullable();
            $table->unsignedInteger('side_view_height')->nullable();
        });
        Schema::create('block_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('item');
            $table->json('points');
            $table->unique(['project_id', 'item']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_areas');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['side_view_path', 'side_view_width', 'side_view_height']);
        });
    }
};
