<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_schedule', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aircraft_id')->constrained('aircraft');
            $table->enum('maintenance_type', ['routine', 'repair', 'inspection', 'overhaul']);
            $table->date('scheduled_date');
            $table->date('end_date')->nullable();
            $table->foreignId('technician_id')->nullable()->constrained('staff');
            $table->boolean('is_completed')->default(false);
        });

        Schema::create('maintenance_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('maintenance_schedule');
            $table->foreignId('aircraft_id')->constrained('aircraft');
            $table->text('work_done');
            $table->text('parts_used')->nullable();
            $table->foreignId('technician_id')->nullable()->constrained('staff');
            $table->text('technician_notes')->nullable();
            $table->timestamp('logged_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_detail');
        Schema::dropIfExists('maintenance_schedule');
    }
};
