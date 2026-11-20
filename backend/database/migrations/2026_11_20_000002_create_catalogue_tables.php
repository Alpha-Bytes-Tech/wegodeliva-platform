<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menus, tags, products and option groups (spec 03 §3.3). Prices are integer kobo; options are
 * charged per unit (spec 05 §5.1). Images are stored as paths into a private Supabase bucket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_menus', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_type_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('slug', 48);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['vendor_type_id', 'slug']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->unsignedBigInteger('price_kobo');
            // Promotional price: if set and lower than price_kobo, it is the selling price.
            $table->unsignedBigInteger('compare_at_price_kobo')->nullable();
            $table->string('unit', 16)->nullable();
            $table->string('unit_size', 32)->nullable();
            $table->unsignedSmallInteger('pack_count')->nullable();
            $table->unsignedInteger('stock_qty')->nullable(); // null = unlimited
            // standard / zero_rated / exempt; null = use the category's treatment (spec 05 §5.6).
            $table->string('vat_treatment', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_deliverable')->default(true);
            $table->boolean('requires_prescription')->default(false);
            $table->boolean('age_restricted')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['vendor_id', 'is_active']);
        });
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_vat_treatment_check CHECK (vat_treatment IS NULL OR vat_treatment IN ('standard', 'zero_rated', 'exempt'))");

        Schema::create('category_product', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'product_id']);
        });

        Schema::create('product_vendor_menu', function (Blueprint $table) {
            $table->foreignId('vendor_menu_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['vendor_menu_id', 'product_id']);
        });

        Schema::create('product_tag', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['tag_id', 'product_id']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('option_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('min_select')->default(0);
            $table->unsignedSmallInteger('max_select')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE option_groups ADD CONSTRAINT option_groups_select_check CHECK (max_select >= 1 AND min_select <= max_select)');

        Schema::create('options', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('option_group_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('price_kobo')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('option_group_product', function (Blueprint $table) {
            $table->foreignId('option_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->primary(['option_group_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_group_product');
        Schema::dropIfExists('options');
        Schema::dropIfExists('option_groups');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_tag');
        Schema::dropIfExists('product_vendor_menu');
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('products');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('vendor_menus');
    }
};
