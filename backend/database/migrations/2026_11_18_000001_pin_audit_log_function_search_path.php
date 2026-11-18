<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supabase security advisor (lint 0011 "function_search_path_mutable"): a function whose
 * search_path can be changed by the caller may resolve names to objects the caller controls.
 * The append-only trigger function references nothing, so an empty search_path is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER FUNCTION admin_audit_log_append_only() SET search_path = ''");
    }

    public function down(): void
    {
        DB::statement('ALTER FUNCTION admin_audit_log_append_only() RESET search_path');
    }
};
