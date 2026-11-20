<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendors, their members, opening hours and the zones they serve (spec 03 §3.3, §3.11).
 * Documents, accepted payment methods and fees arrive with the milestones that use them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_type_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('phone', 16)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('address_line', 255);
            $table->decimal('lat', 9, 6);
            $table->decimal('lng', 9, 6);
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('pending_approval')->index();
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            // Manual override of the schedule (spec 10 "Store status"); null = follow opening hours.
            $table->string('manual_state', 8)->nullable();
            $table->timestamp('manual_state_until')->nullable();
            $table->boolean('accepts_delivery')->default(true);
            $table->boolean('accepts_pickup')->default(false);
            $table->boolean('allows_scheduled_orders')->default(false);
            $table->boolean('auto_accept_orders')->default(false);
            $table->boolean('uses_own_riders')->default(false);
            $table->unsignedSmallInteger('prep_time_minutes')->default(20);
            $table->unsignedSmallInteger('delivery_time_minutes')->default(30);
            $table->unsignedBigInteger('min_order_kobo')->default(0);
            $table->unsignedBigInteger('max_order_kobo')->nullable();
            $table->string('delivery_fee_mode', 8)->default('flat');
            $table->unsignedBigInteger('base_delivery_fee_kobo')->default(0);
            $table->unsignedBigInteger('delivery_fee_kobo')->default(0);
            $table->unsignedInteger('delivery_radius_m')->default(5000);
            $table->unsignedInteger('commission_rate_bp')->nullable();
            $table->boolean('vat_registered')->default(false);
            $table->string('tin', 32)->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'vendor_type_id']);
        });

        DB::statement("ALTER TABLE vendors ADD CONSTRAINT vendors_status_check CHECK (status IN ('pending_approval', 'active', 'suspended'))");
        DB::statement("ALTER TABLE vendors ADD CONSTRAINT vendors_manual_state_check CHECK (manual_state IS NULL OR manual_state IN ('open', 'busy', 'closed'))");
        DB::statement("ALTER TABLE vendors ADD CONSTRAINT vendors_delivery_fee_mode_check CHECK (delivery_fee_mode IN ('zone', 'flat', 'per_km'))");
        DB::statement('ALTER TABLE vendors ADD CONSTRAINT vendors_lat_check CHECK (lat BETWEEN -90 AND 90 AND lng BETWEEN -180 AND 180)');

        Schema::create('vendor_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 8);
            $table->timestamps();
            $table->unique(['vendor_id', 'user_id']);
        });
        DB::statement("ALTER TABLE vendor_members ADD CONSTRAINT vendor_members_role_check CHECK (role IN ('owner', 'staff'))");

        Schema::create('vendor_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 = Sunday … 6 = Saturday
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();
            $table->index(['vendor_id', 'weekday']);
        });
        DB::statement('ALTER TABLE vendor_hours ADD CONSTRAINT vendor_hours_weekday_check CHECK (weekday BETWEEN 0 AND 6)');

        Schema::create('vendor_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('delivery_fee_kobo')->nullable();
            $table->timestamps();
            $table->unique(['vendor_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_zones');
        Schema::dropIfExists('vendor_hours');
        Schema::dropIfExists('vendor_members');
        Schema::dropIfExists('vendors');
    }
};
