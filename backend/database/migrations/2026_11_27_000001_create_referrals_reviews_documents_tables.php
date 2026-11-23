<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Referrals (spec 06 §6.8), reviews (spec 03 §3.8) and vendor/rider documents for approval
 * (spec 03 §3.3, §3.7). Document files live in a private Supabase bucket; only paths are stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('referrer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('referred_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('status', 10)->default('pending');
            $table->unsignedBigInteger('reward_kobo')->default(0);
            $table->foreignId('qualifying_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('rewarded_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['referrer_user_id', 'id']);
        });
        DB::statement("ALTER TABLE referrals ADD CONSTRAINT referrals_status_check CHECK (status IN ('pending', 'rewarded', 'void'))");
        DB::statement('ALTER TABLE referrals ADD CONSTRAINT referrals_not_self_check CHECK (referrer_user_id <> referred_user_id)');

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject_type', 8);
            // vendors.id, users.id (rider) or products.id.
            $table->unsignedBigInteger('subject_id');
            $table->unsignedSmallInteger('rating');
            $table->string('comment', 1000)->nullable();
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
            $table->unique(['order_id', 'subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_id', 'is_visible']);
        });
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_subject_check CHECK (subject_type IN ('vendor', 'rider', 'product'))");
        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');

        Schema::table('rider_profiles', function (Blueprint $table) {
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_type', 8);
            // vendors.id or users.id (rider).
            $table->unsignedBigInteger('owner_id');
            $table->string('kind', 32);
            $table->string('file_path', 255)->nullable();
            $table->string('status', 10);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 255)->nullable();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
            $table->index(['status', 'id']);
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_owner_check CHECK (owner_type IN ('vendor', 'rider'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_status_check CHECK (status IN ('requested', 'submitted', 'approved', 'rejected'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_file_check CHECK (status = 'requested' OR file_path IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::table('rider_profiles', function (Blueprint $table) {
            $table->dropColumn(['rating_avg', 'rating_count']);
        });
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('referrals');
    }
};
