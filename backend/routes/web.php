<?php

use Illuminate\Support\Facades\Route;

// API-only service: the root just identifies itself.
Route::get('/', fn () => response()->json(['name' => 'WeGoDeliva API', 'docs' => '/api/v1']));
