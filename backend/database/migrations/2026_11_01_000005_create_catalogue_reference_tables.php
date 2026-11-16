<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor types and categories (spec 03 §3.3, §3.11). Only the reference data needed for
 * Phase 1 seeding; products, vendors and the rest arrive in Phase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('name', 64);
            $table->text('description')->nullable();
            $table->string('icon_path')->nullable();
            $table->string('color', 9)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_type_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('color', 9)->nullable();
            // standard / zero_rated / exempt; null = not decided yet (accountant to confirm, spec 05 §5.6).
            $table->string('vat_treatment', 16)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['vendor_type_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
        Schema::dropIfExists('vendor_types');
    }
};
