<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank accounts and payouts for vendors and riders (spec 03 §3.6, spec 06 §6.7). Money moves
 * through the gateway's Transfers API; the earnings ledger holds, settles and pays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_type', 8);
            $table->unsignedBigInteger('owner_id');
            $table->string('bank_code', 12);
            $table->string('bank_name', 120);
            $table->char('account_number', 10);
            // As the bank reports it (account-resolve API), never typed by the user.
            $table->string('account_name', 160);
            // verified, or needs_review when the name does not match the vendor or rider.
            $table->string('status', 14);
            $table->boolean('is_default')->default(false);
            // Paystack's transfer recipient, created on first payout.
            $table->string('recipient_code', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['owner_type', 'owner_id']);
        });
        DB::statement("ALTER TABLE payout_accounts ADD CONSTRAINT payout_accounts_owner_check CHECK (owner_type IN ('vendor', 'rider'))");
        DB::statement("ALTER TABLE payout_accounts ADD CONSTRAINT payout_accounts_status_check CHECK (status IN ('verified', 'needs_review'))");
        DB::statement("ALTER TABLE payout_accounts ADD CONSTRAINT payout_accounts_number_check CHECK (account_number ~ '^[0-9]{10}$')");
        DB::statement('CREATE UNIQUE INDEX payout_accounts_unique_live ON payout_accounts (owner_type, owner_id, bank_code, account_number) WHERE deleted_at IS NULL');

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('earnings_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('payout_account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->string('status', 12)->default('requested');
            $table->string('gateway', 12);
            $table->string('transfer_reference', 64)->unique();
            $table->string('gateway_transfer_id', 64)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('idempotency_key', 100);
            $table->timestamps();
            $table->unique(['earnings_account_id', 'idempotency_key']);
            $table->index(['status', 'updated_at']);
        });
        DB::statement("ALTER TABLE payouts ADD CONSTRAINT payouts_status_check CHECK (status IN ('requested', 'approved', 'processing', 'paid', 'rejected', 'failed'))");
        DB::statement('ALTER TABLE payouts ADD CONSTRAINT payouts_amount_check CHECK (amount_kobo > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('payout_accounts');
    }
};
