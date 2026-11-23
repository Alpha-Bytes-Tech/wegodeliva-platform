<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Riders, fulfilment fields on orders, commission records and refunds (spec 03 §3.6–3.7,
 * spec 04 §4.6–4.7, spec 06 §6.6, spec 07). Matching and live tracking arrive in Phase 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('rider_type', 20);
            // Set only for vendor-owned riders: they deliver that vendor's orders only.
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('vehicle_kind', 12)->default('motorbike');
            $table->string('plate_number', 16)->nullable();
            $table->string('status', 20)->default('active');
            $table->boolean('is_online')->default(false);
            $table->unsignedSmallInteger('max_active_orders')->default(1);
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE rider_profiles ADD CONSTRAINT rider_profiles_type_check CHECK (rider_type IN ('wegodeliva_managed', 'independent', 'vendor_owned'))");
        DB::statement("ALTER TABLE rider_profiles ADD CONSTRAINT rider_profiles_vendor_check CHECK ((rider_type = 'vendor_owned') = (vendor_id IS NOT NULL))");
        DB::statement("ALTER TABLE rider_profiles ADD CONSTRAINT rider_profiles_status_check CHECK (status IN ('pending_approval', 'active', 'suspended'))");
        DB::statement("ALTER TABLE rider_profiles ADD CONSTRAINT rider_profiles_vehicle_check CHECK (vehicle_kind IN ('bicycle', 'motorbike', 'car', 'on_foot'))");

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('assigned_at')->nullable();
            // Wrong handover codes entered; three locks the code until support completes the delivery.
            $table->unsignedSmallInteger('handover_attempts')->default(0);
            // pay on delivery: how the rider or vendor was paid at handover.
            $table->string('collected_via', 8)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->string('failure_photo_path', 255)->nullable();
            $table->boolean('customer_called')->default(false);
            // Background alerts already raised, so each fires once (spec 04 §4.6).
            $table->timestamp('vendor_alerted_at')->nullable();
            $table->unsignedSmallInteger('rider_escalation_level')->default(0);
            $table->timestamp('stale_alerted_at')->nullable();
            $table->index(['rider_id', 'status']);
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_collected_via_check CHECK (collected_via IS NULL OR collected_via IN ('cash', 'transfer'))");

        // Written exactly once per delivered order (spec 03 §3.6, spec 05 §5.8).
        Schema::create('commission_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('vendor_gross_kobo');
            $table->unsignedInteger('vendor_rate_bp');
            $table->unsignedBigInteger('vendor_commission_kobo');
            $table->unsignedBigInteger('vendor_commission_vat_kobo');
            $table->unsignedBigInteger('delivery_gross_kobo');
            $table->unsignedInteger('delivery_rate_bp');
            $table->unsignedBigInteger('delivery_commission_kobo');
            $table->unsignedBigInteger('delivery_commission_vat_kobo');
            $table->boolean('delivery_to_vendor');
            $table->unsignedBigInteger('vat_collected_kobo');
            $table->bigInteger('platform_revenue_kobo');
            $table->unsignedBigInteger('tip_kobo');
            $table->timestamp('computed_at');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            // Wallet at launch (spec 06 §6.6); refunds to the card come later.
            $table->string('destination', 16)->default('wallet');
            $table->string('status', 10)->default('succeeded');
            $table->string('reason', 255);
            $table->string('fault', 10);
            $table->string('reference', 120)->unique();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount_kobo > 0)');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_fault_check CHECK (fault IN ('customer', 'vendor', 'rider', 'platform', 'none'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('commission_records');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_collected_via_check');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['rider_id', 'status']);
            $table->dropColumn(['assigned_at', 'handover_attempts', 'collected_via', 'failure_reason', 'failure_photo_path', 'customer_called', 'vendor_alerted_at', 'rider_escalation_level', 'stale_alerted_at']);
        });
        Schema::dropIfExists('rider_profiles');
    }
};
