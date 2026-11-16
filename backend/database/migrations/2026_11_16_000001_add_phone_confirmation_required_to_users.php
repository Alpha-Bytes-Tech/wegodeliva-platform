<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0012: an account whose email is proven for the first time keeps nothing the unverified
 * sign-up chose except name and email. Until the owner re-confirms the phone number, this flag
 * is set and the apps ask for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('phone_confirmation_required')->default(false)->after('phone_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone_confirmation_required');
        });
    }
};
