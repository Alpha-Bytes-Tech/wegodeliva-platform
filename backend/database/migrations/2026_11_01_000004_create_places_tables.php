<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Cities and zones (spec 03 §3.2). zones.polygon is a PostGIS geography when the extension
 * can be enabled; on plain Postgres the column is skipped so the rest of the schema still works.
 */
return new class extends Migration
{
    public function up(): void
    {
        $postgis = $this->enablePostgis();

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->string('state', 80);
            $table->boolean('is_active')->default(true);
            $table->decimal('centre_lat', 9, 6)->nullable();
            $table->decimal('centre_lng', 9, 6)->nullable();
            $table->timestamps();
        });

        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_campus')->default(false)->index();
            $table->unsignedBigInteger('default_delivery_fee_kobo')->nullable();
            $table->unsignedInteger('rider_search_radius_m')->default(3000);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        if ($postgis) {
            DB::statement('ALTER TABLE zones ADD COLUMN polygon geography(MultiPolygon, 4326) NULL');
            DB::statement('CREATE INDEX zones_polygon_gist ON zones USING GIST (polygon)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
        Schema::dropIfExists('cities');
    }

    private function enablePostgis(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        $available = DB::scalar("SELECT count(*) FROM pg_available_extensions WHERE name = 'postgis'") > 0;
        if (! $available) {
            Log::warning('PostGIS is not available; zones.polygon was not created.');

            return false;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        return true;
    }
};
