<?php

use Illuminate\Support\Facades\DB;

it('puts public first and then extensions on the search path, for Supabase PostGIS', function () {
    $searchPath = (string) DB::scalar('show search_path');

    expect($searchPath)->toBe('public, extensions');
});

it('still creates its own tables in public', function () {
    expect(DB::scalar("select table_schema from information_schema.tables where table_name = 'users'"))->toBe('public');
});
