<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flights', function (Blueprint $table) {
            $table->id();
            $table->string('flight_number')->unique();
            $table->foreignId('route_id')->constrained('routes');
            $table->foreignId('aircraft_id')->constrained('aircraft');
            $table->foreignId('gate_id')->nullable()->constrained('gates');
            $table->timestamp('departure_time');
            $table->timestamp('arrival_time');
            $table->enum('status', [
                'scheduled', 'boarding', 'departed', 'in_air',
                'landed', 'arrived', 'delayed', 'cancelled',
            ])->default('scheduled');
            $table->decimal('base_price', 8, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('internal_users');
        });

        Schema::create('flight_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights');
            $table->foreignId('aircraft_seat_id')->constrained('aircraft_seats');
            $table->boolean('is_available')->default(true);
            $table->unique(['flight_id', 'aircraft_seat_id']);
        });

        Schema::create('flight_class_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights');
            $table->enum('seat_class', ['economy', 'business', 'first_class']);
            $table->decimal('price', 8, 2);
            $table->unique(['flight_id', 'seat_class']);
        });

        Schema::create('flight_status_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('flight_id')->constrained('flights');
            $table->enum('old_status', [
                'scheduled', 'boarding', 'departed', 'in_air',
                'landed', 'arrived', 'delayed', 'cancelled',
            ])->nullable();
            $table->enum('new_status', [
                'scheduled', 'boarding', 'departed', 'in_air',
                'landed', 'arrived', 'delayed', 'cancelled',
            ]);
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->constrained('internal_users');
            $table->timestamp('changed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_status_log');
        Schema::dropIfExists('flight_class_prices');
        Schema::dropIfExists('flight_seats');
        Schema::dropIfExists('flights');
    }
};
