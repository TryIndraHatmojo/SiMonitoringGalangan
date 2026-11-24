<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('baselines', function (Blueprint $table) {
            $table->json('estimate')->nullable();
            $table->json('schedule')->nullable();
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->json('estimate')->nullable();
        });
        Schema::table('report_periods', function (Blueprint $table) {
            $table->decimal('actual_cost', 20, 2)->nullable();
            $table->string('cost_source')->nullable();
            $table->boolean('cost_simulated')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closing_reason')->nullable();
        });
        Schema::create('period_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('report_periods')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->json('changes');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_corrections');
        Schema::table('report_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['actual_cost', 'cost_source', 'cost_simulated', 'verified_at', 'closing_reason']);
        });
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('estimate'));
        Schema::table('baselines', fn (Blueprint $table) => $table->dropColumn(['estimate', 'schedule']));
    }
};
