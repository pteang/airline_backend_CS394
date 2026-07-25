<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff');
            $table->date('available_date');
            $table->enum('status', ['available', 'unavailable', 'on_leave', 'assigned']);
            $table->string('reason')->nullable();
            $table->unique(['staff_id', 'available_date']);
        });

        Schema::create('crew_assignment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights');
            $table->foreignId('staff_id')->constrained('staff');
            $table->enum('assignment_role', ['captain', 'first_officer', 'purser', 'flight_attendant', 'ground_crew']);
            $table->foreignId('assigned_by')->constrained('internal_users');
            $table->timestamp('assigned_at')->useCurrent();
            $table->unique(['flight_id', 'staff_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_assignment');
        Schema::dropIfExists('staff_availability');
    }
};
