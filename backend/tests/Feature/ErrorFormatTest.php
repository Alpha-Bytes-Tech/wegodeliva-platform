<?php

use Illuminate\Support\Facades\Route;

it('uses the error envelope for unknown routes', function () {
    expectApiError($this->getJson('/api/v1/does-not-exist'), 404, 'not_found');
});

it('uses the error envelope for unsupported methods', function () {
    expectApiError($this->putJson('/api/v1/health'), 405, 'method_not_allowed');
});

it('requires authentication with a 401 envelope, never a redirect', function () {
    expectApiError($this->get('/api/v1/auth/me'), 401, 'unauthenticated');
});

it('hides exception details behind a generic 500', function () {
    Route::get('/api/v1/_boom', fn () => throw new RuntimeException('secret stack detail'));

    $response = $this->getJson('/api/v1/_boom');

    expectApiError($response, 500, 'server_error');
    expect($response->getContent())->not->toContain('secret stack detail');
});

it('returns validation errors as 400 with fields', function () {
    $error = expectApiError($this->postJson('/api/v1/auth/login', []), 400, 'validation_failed');

    expect($error['fields'])->toHaveKeys(['email', 'password', 'app']);
});
