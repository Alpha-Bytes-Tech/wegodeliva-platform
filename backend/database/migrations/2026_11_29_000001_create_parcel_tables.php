<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parcels (spec 03 §3.4–3.5, spec 05 §5.3): package types, each operator's rates per package
 * type, the stops of a parcel order and the parcel details on the order itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->string('image_path', 255)->nullable();
            // Each drop-off gets its own code the recipient gives the rider.
            $table->boolean('requires_stop_verification')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('parcel_rates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_type_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('base_price_kobo');
            // Per km of route when distance_per_km, else a flat amount.
            $table->unsignedBigInteger('distance_rate_kobo')->default(0);
            $table->boolean('distance_per_km')->default(true);
            // Per kg when size_per_kg, else a flat amount.
            $table->unsignedBigInteger('size_rate_kobo')->default(0);
            $table->boolean('size_per_kg')->default(false);
            $table->unsignedBigInteger('extra_stop_fee_kobo')->default(0);
            $table->unsignedSmallInteger('max_advance_booking_days')->default(3);
            $table->boolean('auto_dispatch')->default(false);
            $table->boolean('requires_dimensions')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['vendor_id', 'package_type_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('package_type_id')->nullable()->after('coupon_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('weight_g')->nullable()->after('package_type_id');
            $table->unsignedSmallInteger('length_cm')->nullable()->after('weight_g');
            $table->unsignedSmallInteger('width_cm')->nullable()->after('length_cm');
            $table->unsignedSmallInteger('height_cm')->nullable()->after('width_cm');
            // sender | recipient: who pays (a paying recipient pays on delivery).
            $table->string('payer', 10)->nullable()->after('height_cm');
        });

        Schema::create('order_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('kind', 8);
            // Snapshot: line1, landmark, area, lat, lng.
            $table->jsonb('address');
            $table->string('contact_name', 120);
            $table->string('contact_phone', 20);
            $table->string('note', 255)->nullable();
            // Encrypted, not hashed: the sender's app shows it so it can be shared with the recipient.
            $table->text('verification_code')->nullable();
            $table->unsignedSmallInteger('verify_attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'sequence']);
        });

        DB::statement("ALTER TABLE order_stops ADD CONSTRAINT order_stops_kind_check CHECK (kind IN ('pickup', 'dropoff'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payer_check CHECK (payer IS NULL OR payer IN ('sender', 'recipient'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_stops');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payer_check');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_type_id');
            $table->dropColumn(['weight_g', 'length_cm', 'width_cm', 'height_cm', 'payer']);
        });
        Schema::dropIfExists('parcel_rates');
        Schema::dropIfExists('package_types');
    }
};
