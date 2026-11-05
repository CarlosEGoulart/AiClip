<?php

namespace Tests;

use App\Services\ProcessMediaAction;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
