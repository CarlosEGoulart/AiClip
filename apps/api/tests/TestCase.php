<?php

namespace Tests;

use App\Services\ProcessMediaAction;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Mockery;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure database tables exist for all tests. RefreshDatabase::setUp()
        // is shadowed by TestCase::setUp() (class method takes precedence over
        // trait method), so run migrations here as a fallback for tests that
        // don't extend SpaTestCase (which has its own migration guard).
        if (!Schema::hasTable('users')) {
            $this->artisan('migrate', ['--force' => true]);
        }

        // Mock ProcessMediaAction to prevent actual worker invocation during testing
        $actionMock = Mockery::mock(ProcessMediaAction::class);
        $actionMock->shouldReceive('probe')
            ->andReturn([
                'status' => 'success',
                'probe' => ['duration_ms' => 1000],
            ]);
        app()->instance(ProcessMediaAction::class, $actionMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
