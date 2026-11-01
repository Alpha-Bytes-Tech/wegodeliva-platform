<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes, social identities, push devices and the admin audit log (spec 03 §3.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 16)->default('email'); // sms / whatsapp LATER
            $table->string('destination', 191);
            $table->string('purpose', 16); // login / verify / reset
            $table->string('app', 16); // which app asked: shown in the email
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['destination', 'purpose', 'created_at']);
        });

        Schema::create('auth_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16); // google / apple
            $table->string('provider_user_id', 191);
            $table->string('email', 191)->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('app', 16); // customer / vendor / rider / admin
            $table->string('platform', 16); // android / ios / web
            $table->string('fcm_token', 512)->unique();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64)->index();
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['subject_type', 'subject_id']);
        });

        // Append-only: the database itself refuses updates and deletes.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION admin_audit_log_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'admin_audit_log is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER admin_audit_log_no_update_delete
                BEFORE UPDATE OR DELETE ON admin_audit_log
                FOR EACH ROW EXECUTE FUNCTION admin_audit_log_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS admin_audit_log_no_update_delete ON admin_audit_log');
        DB::unprepared('DROP FUNCTION IF EXISTS admin_audit_log_append_only()');
        Schema::dropIfExists('admin_audit_log');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('auth_identities');
        Schema::dropIfExists('otp_challenges');
    }
};
