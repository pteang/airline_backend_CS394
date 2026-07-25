<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('booking_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id');
            $table->text('note')->nullable();
            $table->timestamp('changed_at')->useCurrent();
        });

        Schema::create('aircraft_assignment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aircraft_id')->constrained('aircraft');
            $table->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('internal_users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('aircraft_assignment_logs');
        Schema::dropIfExists('booking_logs');
        Schema::dropIfExists('password_reset_tokens');
    }
};
