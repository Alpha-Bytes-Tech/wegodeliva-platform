<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer wallets and vendor/rider earnings (spec 03 §3.6, spec 05 §5.8, spec 06 §6.4–6.7).
 * Balances are cached sums of append-only ledgers: a row's balance only changes together with
 * a new ledger entry, inside a transaction holding a lock on that row. The database refuses
 * updates and deletes on the ledger tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            // Signed: only an admin adjustment may take a wallet below zero.
            $table->bigInteger('balance_kobo')->default(0);
            $table->char('currency', 3)->default('NGN');
            $table->unsignedSmallInteger('kyc_tier')->default(1);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_kyc_tier_check CHECK (kyc_tier BETWEEN 1 AND 3)');

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->string('direction', 6);
            $table->unsignedBigInteger('amount_kobo');
            $table->bigInteger('balance_after_kobo');
            $table->string('kind', 20);
            // Our idempotency key: posting the same reference twice is a no-op.
            $table->string('reference', 120)->unique();
            // Orders and payments arrive in milestones 2.4–2.5, which add the foreign keys.
            $table->unsignedBigInteger('order_id')->nullable()->index();
            // Which gateway holds the money (per-gateway breakdown, spec 06 §6.4.1).
            $table->string('gateway', 12)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['wallet_id', 'id']);
        });
        DB::statement("ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_direction_check CHECK (direction IN ('credit', 'debit'))");
        DB::statement('ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_amount_check CHECK (amount_kobo > 0)');
        DB::statement("ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_kind_check CHECK (kind IN ('topup', 'order_payment', 'refund', 'referral_reward', 'admin_adjustment', 'reversal'))");

        Schema::create('earnings_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_type', 8);
            // vendors.id for vendors, users.id for riders.
            $table->unsignedBigInteger('owner_id');
            // Gross earnings not yet paid out; may go negative when a reversal exceeds it.
            $table->bigInteger('balance_kobo')->default(0);
            $table->unsignedBigInteger('commission_due_kobo')->default(0);
            $table->unsignedBigInteger('commission_vat_due_kobo')->default(0);
            // Riders: pay-on-delivery cash collected and owed to WeGoDeliva.
            $table->unsignedBigInteger('cash_owed_kobo')->default(0);
            // Requested payouts not yet paid or released.
            $table->unsignedBigInteger('held_kobo')->default(0);
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id']);
        });
        DB::statement("ALTER TABLE earnings_accounts ADD CONSTRAINT earnings_accounts_owner_type_check CHECK (owner_type IN ('vendor', 'rider'))");

        Schema::create('earnings_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('earnings_account_id')->constrained()->restrictOnDelete();
            $table->string('kind', 24);
            // Which figure of the account the entry moves, and by how much (signed).
            $table->string('bucket', 20);
            $table->bigInteger('amount_kobo');
            $table->bigInteger('bucket_after_kobo');
            // One operation (e.g. an order's earnings) posts several entries under one reference.
            $table->string('reference', 120);
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['earnings_account_id', 'reference', 'kind', 'bucket']);
            $table->index(['earnings_account_id', 'id']);
        });
        DB::statement("ALTER TABLE earnings_entries ADD CONSTRAINT earnings_entries_bucket_check CHECK (bucket IN ('balance', 'commission_due', 'commission_vat_due', 'cash_owed', 'held'))");
        DB::statement('ALTER TABLE earnings_entries ADD CONSTRAINT earnings_entries_amount_check CHECK (amount_kobo <> 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_append_only() RETURNS trigger
                LANGUAGE plpgsql SET search_path = '' AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only', TG_TABLE_NAME;
            END;
            $$;

            CREATE TRIGGER wallet_transactions_append_only
                BEFORE UPDATE OR DELETE ON wallet_transactions
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();

            CREATE TRIGGER earnings_entries_append_only
                BEFORE UPDATE OR DELETE ON earnings_entries
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS earnings_entries_append_only ON earnings_entries');
        DB::unprepared('DROP TRIGGER IF EXISTS wallet_transactions_append_only ON wallet_transactions');
        Schema::dropIfExists('earnings_entries');
        Schema::dropIfExists('earnings_accounts');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_append_only()');
    }
};
