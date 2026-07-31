<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airports', function (Blueprint $table) {
            $table->id();
            $table->string('iata_code')->unique();
            $table->string('name');
            $table->string('city');
            $table->string('country');
        });

        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('origin_airport_id')->constrained('airports');
            $table->foreignId('destination_airport_id')->constrained('airports');
            $table->integer('distance_km')->nullable();
            $table->integer('estimated_duration')->nullable(); // minutes
            $table->boolean('is_active')->default(true);
        });

        Schema::create('aircraft', function (Blueprint $table) {
            $table->id();
            $table->string('registration_number')->unique();
            $table->string('model');
            $table->integer('capacity');
            $table->enum('status', ['available', 'assigned', 'maintenance', 'retired'])->default('available');
            $table->string('manufacturer')->nullable();
            $table->integer('flight_hours')->default(0);
        });

        Schema::create('aircraft_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aircraft_id')->constrained('aircraft');
            $table->string('seat_number');
            $table->enum('seat_class', ['economy', 'business', 'first_class']);
            $table->boolean('is_window')->nullable();
            $table->boolean('is_aisle')->nullable();
            $table->unique(['aircraft_id', 'seat_number']);
        });

        Schema::create('gates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airport_id')->constrained('airports');
            $table->string('gate_code');
            $table->enum('status', ['available', 'occupied', 'maintenance', 'closed'])->default('available');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gates');
        Schema::dropIfExists('aircraft_seats');
        Schema::dropIfExists('aircraft');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('airports');
    }
};
