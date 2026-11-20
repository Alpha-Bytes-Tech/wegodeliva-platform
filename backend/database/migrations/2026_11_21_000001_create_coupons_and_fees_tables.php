<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons and service fees (spec 03 §3.6, spec 05 §5.4–5.5). Amounts are kobo, percentages
 * are basis points. Redemptions are written when an order is placed (milestone 2.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Stored upper-case; customers may type any case.
            $table->string('code', 32)->unique();
            $table->string('description', 255)->nullable();
            $table->string('discount_type', 8);
            // Basis points for percent coupons, kobo for fixed ones.
            $table->unsignedBigInteger('value');
            $table->unsignedBigInteger('max_discount_kobo')->nullable();
            $table->unsignedBigInteger('min_order_kobo')->default(0);
            $table->string('applies_to', 16)->default('order_subtotal');
            $table->string('scope', 16)->default('all');
            $table->foreignId('vendor_type_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('max_uses_total')->nullable();
            $table->unsignedInteger('max_uses_per_user')->nullable();
            $table->boolean('first_order_only')->default(false);
            $table->string('funded_by', 8)->default('vendor');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_discount_type_check CHECK (discount_type IN ('percent', 'fixed'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_percent_check CHECK (discount_type <> 'percent' OR value BETWEEN 1 AND 10000)");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_applies_to_check CHECK (applies_to IN ('order_subtotal', 'delivery_fee'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_scope_check CHECK (scope IN ('all', 'vendor_type', 'vendors', 'products'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_vendor_type_check CHECK ((scope = 'vendor_type') = (vendor_type_id IS NOT NULL))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_funded_by_check CHECK (funded_by IN ('platform', 'vendor'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_delivery_platform_check CHECK (applies_to <> 'delivery_fee' OR funded_by = 'platform')");
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_dates_check CHECK (starts_at IS NULL OR ends_at IS NULL OR starts_at < ends_at)');

        Schema::create('coupon_vendor', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->primary(['coupon_id', 'vendor_id']);
        });

        Schema::create('coupon_product', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['coupon_id', 'product_id']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // The orders table arrives in milestone 2.4, which adds the foreign key.
            $table->unsignedBigInteger('order_id')->nullable()->unique();
            $table->unsignedBigInteger('discount_kobo');
            // Released when the order is cancelled before it is accepted, so the use is given back.
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['coupon_id', 'user_id']);
        });

        Schema::create('fees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 60);
            $table->string('type', 8);
            // Basis points for percent fees, kobo for fixed ones.
            $table->unsignedBigInteger('value');
            $table->string('beneficiary', 8);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE fees ADD CONSTRAINT fees_type_check CHECK (type IN ('percent', 'fixed'))");
        DB::statement("ALTER TABLE fees ADD CONSTRAINT fees_percent_check CHECK (type <> 'percent' OR value <= 10000)");
        DB::statement("ALTER TABLE fees ADD CONSTRAINT fees_beneficiary_check CHECK (beneficiary IN ('platform', 'vendor'))");

        Schema::create('fee_vendor', function (Blueprint $table) {
            $table->foreignId('fee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->primary(['fee_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_vendor');
        Schema::dropIfExists('fees');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupon_product');
        Schema::dropIfExists('coupon_vendor');
        Schema::dropIfExists('coupons');
    }
};
