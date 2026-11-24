<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer app needs beyond Phase 2 (Phase 3 plan, milestone 3.1): admin-managed
 * content pages and banners, support tickets ("Report a problem"), and a pending email address
 * while a change of email waits for its code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email', 191)->nullable()->after('email');
        });

        // Terms, privacy, about, FAQs (spec 09 "Legal / content pages"). Body is Markdown.
        Schema::create('content_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 48)->unique();
            $table->string('title', 120);
            $table->text('body');
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });

        // Home carousel and onboarding slides (spec 09 "Home", "Onboarding").
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('placement', 12);
            $table->string('title', 120);
            $table->string('subtitle', 255)->nullable();
            $table->string('image_path', 255);
            // An in-app deep link such as /v/{slug} or /p/{id}.
            $table->string('link', 255)->nullable();
            $table->foreignId('vendor_type_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['placement', 'is_active']);
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 24);
            $table->text('message');
            // Paths in private storage under support/{user uuid}/.
            $table->jsonb('photo_paths')->default('[]');
            $table->string('status', 12)->default('open');
            $table->text('resolution')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'id']);
            $table->index(['status', 'id']);
        });

        DB::statement("ALTER TABLE banners ADD CONSTRAINT banners_placement_check CHECK (placement IN ('home', 'onboarding'))");
        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_status_check CHECK (status IN ('open', 'in_progress', 'resolved', 'closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('banners');
        Schema::dropIfExists('content_pages');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('pending_email'));
    }
};
