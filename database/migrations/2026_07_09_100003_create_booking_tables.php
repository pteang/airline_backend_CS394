<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_ref')->unique();
            // The account that made the booking.
            $table->foreignId('passenger_id')->constrained('users');
            $table->foreignId('flight_id')->constrained('flights');
            $table->enum('trip_type', ['one_way', 'round_trip'])->default('one_way');
            $table->foreignId('return_flight_id')->nullable()->constrained('flights');
            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'completed'])->default('pending');
            $table->timestamp('booked_at')->useCurrent();
        });

        Schema::create('booking_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings');
            // One traveller on the booking (references a passenger profile).
            $table->foreignId('passenger_id')->constrained('passenger_profiles');
            $table->foreignId('flight_seat_id')->nullable()->unique()->constrained('flight_seats');
            $table->enum('seat_class', ['economy', 'business', 'first_class']);
            $table->text('special_request')->nullable();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings');
            $table->decimal('amount', 8, 2);
            $table->enum('payment_method', ['credit_card', 'debit_card', 'paypal', 'bank_transfer', 'cash', 'aba_pay', 'acleda', 'wing']);
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded'])->default('pending');
            $table->string('transaction_ref')->unique();
            $table->timestamp('paid_at')->nullable();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings');
            $table->string('ticket_number')->unique();
            $table->timestamp('issued_at')->useCurrent();
            $table->string('ticket_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('booking_passengers');
        Schema::dropIfExists('bookings');
    }
};
