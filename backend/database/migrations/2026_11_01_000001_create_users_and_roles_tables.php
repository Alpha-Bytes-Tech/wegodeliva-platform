<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * People and access (docs/spec/03-entities.md §3.1). Roles are a plain table (ADR 0009).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            // E.164, required; format-checked but not verified at launch (ADR 0008).
            $table->string('phone', 16)->unique();
            // Stored lower-cased; verified by email OTP.
            $table->string('email', 191)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            // Nullable: OTP-only and social sign-in users have no password.
            $table->string('password')->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->string('avatar_path')->nullable();
            $table->string('referral_code', 12)->unique();
            $table->foreignId('referred_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('kyc_tier')->default(1);
            $table->string('locale', 8)->default('en');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'pending_approval', 'suspended', 'deleted'))");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_kyc_tier_check CHECK (kyc_tier BETWEEN 1 AND 3)');

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();
            $table->string('label', 64);
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'role_id']);
        });

        // Roles are part of the schema, not optional seed data: every environment needs them.
        $now = now();
        DB::table('roles')->insert(array_map(fn (array $role) => $role + ['created_at' => $now, 'updated_at' => $now], [
            ['name' => 'customer', 'label' => 'Customer'],
            ['name' => 'vendor_owner', 'label' => 'Vendor owner'],
            ['name' => 'vendor_staff', 'label' => 'Vendor staff'],
            ['name' => 'rider', 'label' => 'Rider'],
            ['name' => 'admin', 'label' => 'Admin'],
            ['name' => 'super_admin', 'label' => 'Super admin'],
            ['name' => 'zone_admin', 'label' => 'Zone admin'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
    }
};
