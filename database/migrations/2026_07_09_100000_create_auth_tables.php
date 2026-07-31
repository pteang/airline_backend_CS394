<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Passenger-facing accounts.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        // Internal (staff-facing) accounts.
        Schema::create('internal_users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('phone')->nullable();
            $table->enum('role', ['admin', 'manager', 'agent']);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('passenger_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->string('passport_number')->nullable();
            $table->string('nationality')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('special_needs')->nullable();
        });

        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internal_user_id')->unique()->constrained('internal_users');
            $table->string('employee_id')->unique();
            $table->string('license_number')->nullable();
            $table->date('license_expiry')->nullable();
            $table->enum('staff_role', ['pilot', 'copilot', 'cabin_crew', 'manager', 'technician', 'ground_staff', 'engineer']);
            $table->date('joined_date');
        });

        // Token-based session store. A session belongs to exactly one owner:
        // either a passenger (user_id) or an internal user (internal_user_id).
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internal_user_id')->nullable()->constrained('internal_users');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('session_token')->unique();
            $table->timestamp('login_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('logout_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->index('user_id', 'idx_sessions_user');
            $table->index('internal_user_id', 'idx_sessions_internal_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('staff');
        Schema::dropIfExists('passenger_profiles');
        Schema::dropIfExists('internal_users');
        Schema::dropIfExists('users');
    }
};
