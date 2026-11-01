<?php

use Illuminate\Support\Facades\DB;

it('answers the liveness probe', function () {
    $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['data' => ['status' => 'ok']]);
});

it('reports database, cache and queue as ready', function () {
    $this->getJson('/api/v1/health/ready')
        ->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.checks', ['database' => 'ok', 'cache' => 'ok', 'queue' => 'ok']);
});

it('returns 503 without internals when a dependency fails', function () {
    DB::shouldReceive('select')->andThrow(new RuntimeException('connection refused to 10.0.0.1'));

    $response = $this->getJson('/api/v1/health/ready');

    $response->assertStatus(503)->assertJsonPath('data.checks.database', 'fail');
    expect($response->getContent())->not->toContain('10.0.0.1');
});

it('identifies the service at the root', function () {
    $this->getJson('/')->assertOk()->assertJsonPath('name', 'WeGoDeliva API');
});
