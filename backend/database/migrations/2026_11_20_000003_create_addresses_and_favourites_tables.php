<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Customer addresses (with landmarks, spec 03 §3.2) and favourites (spec 03 §3.3). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40)->nullable();
            $table->string('line1', 255);
            $table->string('landmark', 255)->nullable();
            $table->string('area', 120)->nullable();
            $table->decimal('lat', 9, 6);
            $table->decimal('lng', 9, 6);
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('campus_building', 120)->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 16)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'deleted_at']);
        });
        DB::statement('ALTER TABLE addresses ADD CONSTRAINT addresses_lat_check CHECK (lat BETWEEN -90 AND 90 AND lng BETWEEN -180 AND 180)');

        Schema::create('favourites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'vendor_id']);
            $table->unique(['user_id', 'product_id']);
        });
        DB::statement('ALTER TABLE favourites ADD CONSTRAINT favourites_target_check CHECK ((vendor_id IS NULL) <> (product_id IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('favourites');
        Schema::dropIfExists('addresses');
    }
};
