<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gateway payments, wallet top-ups, inbound webhooks and daily reconciliation reports
 * (spec 03 §3.6, spec 06 §6.2–6.4). Gateway keys never live in the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_topups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->string('gateway', 12);
            $table->string('status', 10)->default('pending');
            $table->string('idempotency_key', 100);
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE wallet_topups ADD CONSTRAINT wallet_topups_status_check CHECK (status IN ('pending', 'succeeded', 'failed'))");

        // One row per checkout attempt; an order may have several (retries).
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('wallet_topup_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('gateway', 12);
            // Our own unique reference, sent to the gateway and checked on verification.
            $table->string('reference', 64)->unique();
            $table->string('gateway_transaction_id', 64)->nullable();
            $table->unsignedBigInteger('amount_kobo');
            $table->char('currency', 3)->default('NGN');
            $table->string('status', 10)->default('initiated');
            $table->string('channel', 20)->nullable();
            $table->string('failure_reason', 120)->nullable();
            $table->text('checkout_url')->nullable();
            // Selected, non-sensitive fields of the gateway's answer; never card or account data.
            $table->jsonb('raw_verification')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'id']);
            $table->index(['status', 'created_at']);
            // Set only on success: one gateway transaction can pay for one thing, once.
            $table->unique(['gateway', 'gateway_transaction_id']);
        });
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('initiated', 'pending', 'succeeded', 'failed', 'abandoned'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_gateway_check CHECK (gateway IN ('paystack', 'flutterwave'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_target_check CHECK ((order_id IS NULL) <> (wallet_topup_id IS NULL))');

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 12);
            $table->string('event_type', 60);
            // The gateway's event identity: a replayed delivery hits the unique key and is ignored.
            $table->string('event_key', 120);
            $table->string('reference', 64)->nullable()->index();
            $table->jsonb('payload');
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_error', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['gateway', 'event_key']);
        });

        Schema::create('reconciliation_reports', function (Blueprint $table) {
            $table->id();
            $table->date('run_on')->unique();
            $table->jsonb('ledger');
            $table->jsonb('gateways');
            $table->bigInteger('owed_kobo');
            $table->bigInteger('held_kobo')->nullable();
            // Positive when the gateways hold less than WeGoDeliva owes its users.
            $table->bigInteger('shortfall_kobo')->nullable();
            $table->string('status', 12);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE reconciliation_reports ADD CONSTRAINT reconciliation_reports_status_check CHECK (status IN ('ok', 'shortfall', 'incomplete'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_reports');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('wallet_topups');
    }
};
