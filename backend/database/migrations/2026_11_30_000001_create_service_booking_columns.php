<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service bookings (spec 03 "Services", spec 04 §4.8, spec 05 §5.9). A service is a product of
 * a services store with a pricing unit (ADR 0014), so it reuses images, categories, options and
 * reviews. A booking is an order with one service line, a time slot and its own statuses.
 */
return new class extends Migration
{
    private const STATUSES = "'awaiting_payment', 'awaiting_review', 'scheduled', 'placed', 'accepted', 'ready', 'picked_up', 'delivered', 'cancelled', 'failed', 'requested', 'confirmed', 'in_progress', 'completed', 'disputed'";

    private const OLD_STATUSES = "'awaiting_payment', 'awaiting_review', 'scheduled', 'placed', 'accepted', 'ready', 'picked_up', 'delivered', 'cancelled', 'failed'";

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // null for goods; fixed | hour | day | month for services.
            $table->string('pricing_unit', 8)->nullable()->after('price_kobo');
            $table->unsignedSmallInteger('min_units')->nullable()->after('pricing_unit');
            $table->unsignedSmallInteger('max_units')->nullable()->after('min_units');
            // at_customer | at_provider | either
            $table->string('location_mode', 12)->nullable()->after('max_units');
            // none | flat | per_km: charged when the service happens at the customer's address.
            $table->string('callout_fee_mode', 8)->nullable()->after('location_mode');
            $table->unsignedBigInteger('callout_fee_kobo')->default(0)->after('callout_fee_mode');
            // Bookings the provider can serve at the same time.
            $table->unsignedSmallInteger('capacity')->nullable()->after('callout_fee_kobo');
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('capacity');
        });

        Schema::table('options', function (Blueprint $table) {
            // Service options are charged once per booking unless per unit (goods: always per unit).
            $table->boolean('per_unit')->default(true)->after('price_kobo');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('units')->nullable()->after('payer');
            $table->timestamp('starts_at')->nullable()->after('units');
            $table->timestamp('ends_at')->nullable()->after('starts_at');
            // at_customer | at_provider
            $table->string('service_location', 12)->nullable()->after('ends_at');
            $table->timestamp('confirmed_at')->nullable()->after('accepted_at');
            $table->timestamp('started_at')->nullable()->after('confirmed_at');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            // When the customer confirmed (or it auto-confirmed): earnings are credited then.
            $table->timestamp('completion_confirmed_at')->nullable()->after('completed_at');
            $table->timestamp('disputed_at')->nullable()->after('completion_confirmed_at');
            $table->string('dispute_reason', 500)->nullable()->after('disputed_at');
            $table->timestamp('reminded_day_at')->nullable()->after('stale_alerted_at');
            $table->timestamp('reminded_hour_at')->nullable()->after('reminded_day_at');
            $table->index(['vendor_id', 'starts_at']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            // Booked units of a service line (the line's quantity is 1).
            $table->unsignedSmallInteger('units')->nullable()->after('quantity');
        });

        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('.self::STATUSES.'))');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_pricing_unit_check CHECK (pricing_unit IS NULL OR pricing_unit IN ('fixed', 'hour', 'day', 'month'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_location_mode_check CHECK (location_mode IS NULL OR location_mode IN ('at_customer', 'at_provider', 'either'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_service_location_check CHECK (service_location IS NULL OR service_location IN ('at_customer', 'at_provider'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_service_location_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('.self::OLD_STATUSES.'))');
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('units'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['vendor_id', 'starts_at']);
            $table->dropColumn(['units', 'starts_at', 'ends_at', 'service_location', 'confirmed_at', 'started_at', 'completed_at', 'completion_confirmed_at', 'disputed_at', 'dispute_reason', 'reminded_day_at', 'reminded_hour_at']);
        });
        Schema::table('options', fn (Blueprint $table) => $table->dropColumn('per_unit'));
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_location_mode_check');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_pricing_unit_check');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['pricing_unit', 'min_units', 'max_units', 'location_mode', 'callout_fee_mode', 'callout_fee_kobo', 'capacity', 'duration_minutes']);
        });
    }
};
