<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quotes, orders, their lines, fees and status history (spec 03 §3.5, spec 04). Orders keep
 * snapshots of prices, options and the address so later catalogue edits never change them.
 */
return new class extends Migration
{
    private const STATUSES = "'awaiting_payment', 'awaiting_review', 'scheduled', 'placed', 'accepted', 'ready', 'picked_up', 'delivered', 'cancelled', 'failed'";

    public function up(): void
    {
        // A priced cart, bound to its customer and valid for a few minutes (spec 04 §4.4).
        Schema::create('order_quotes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->jsonb('input');
            $table->jsonb('breakdown');
            $table->unsignedBigInteger('total_kobo');
            // Hash of the breakdown: placement recomputes and compares.
            $table->char('fingerprint', 64);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 12)->unique();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('rider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('vendor_type_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->string('fulfilment', 8);
            $table->string('status', 20);
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('payment_method', 16);
            $table->foreignId('delivery_address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->jsonb('address_snapshot')->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->boolean('campus_mode')->default(false);
            $table->timestamp('scheduled_for')->nullable();
            // When a scheduled order is released to the vendor: scheduled time − prep − buffer.
            $table->timestamp('release_at')->nullable();
            $table->text('note')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->string('cancelled_by', 12)->nullable();
            // customer / vendor / rider / platform / none (spec 06 §6.6); set when cancelled or failed.
            $table->string('fault', 10)->nullable();
            $table->unsignedBigInteger('subtotal_kobo');
            $table->unsignedBigInteger('discount_kobo')->default(0);
            $table->string('discount_funded_by', 8)->nullable();
            $table->unsignedBigInteger('delivery_fee_kobo')->default(0);
            $table->unsignedBigInteger('delivery_discount_kobo')->default(0);
            $table->unsignedBigInteger('service_fee_kobo')->default(0);
            $table->unsignedBigInteger('tax_kobo')->default(0);
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->unsignedBigInteger('tip_kobo')->default(0);
            $table->unsignedBigInteger('total_kobo');
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            // Encrypted, not hashed: the customer's app shows it until delivery.
            $table->text('handover_code');
            $table->unsignedSmallInteger('prep_time_minutes')->nullable();
            $table->timestamp('estimated_ready_at')->nullable();
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->foreignId('quote_id')->nullable()->unique()->constrained('order_quotes')->nullOnDelete();
            // The client's Idempotency-Key: a double tap returns the first order (spec 04 §4.4).
            $table->string('idempotency_key', 100);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['customer_id', 'idempotency_key']);
            $table->index(['customer_id', 'id']);
            $table->index(['vendor_id', 'status']);
            $table->index(['status', 'release_at']);
        });
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('.self::STATUSES.'))');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('unpaid', 'pending', 'paid', 'failed', 'refunded', 'partially_refunded'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_method_check CHECK (payment_method IN ('paystack', 'flutterwave', 'wallet', 'pay_on_delivery'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_fulfilment_check CHECK (fulfilment IN ('delivery', 'pickup'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_delivery_address_check CHECK (fulfilment = 'pickup' OR address_snapshot IS NOT NULL)");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_check CHECK (total_kobo = subtotal_kobo - discount_kobo + delivery_fee_kobo - delivery_discount_kobo + service_fee_kobo + tax_kobo + tip_kobo)');

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name', 160);
            $table->unsignedBigInteger('unit_price_kobo');
            // Chosen options: [{group_id, group, option_id, option, price_kobo}], charged per unit.
            $table->jsonb('options');
            $table->unsignedBigInteger('options_kobo');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('line_total_kobo');
            $table->unsignedBigInteger('discount_kobo')->default(0);
            $table->string('vat_treatment', 16);
            $table->unsignedInteger('vat_rate_bp');
            $table->unsignedBigInteger('vat_kobo');
            // Stock was taken for this line and must go back if the order is cancelled.
            $table->boolean('stock_reserved')->default(false);
            $table->timestamps();
        });

        Schema::create('order_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 60);
            $table->unsignedBigInteger('amount_kobo');
            $table->string('beneficiary', 8);
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 10);
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'id']);
        });
        DB::statement('CREATE TRIGGER order_status_history_append_only BEFORE UPDATE OR DELETE ON order_status_history FOR EACH ROW EXECUTE FUNCTION ledger_append_only()');

        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });
        DB::unprepared('DROP TRIGGER IF EXISTS order_status_history_append_only ON order_status_history');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_fees');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_quotes');
    }
};
