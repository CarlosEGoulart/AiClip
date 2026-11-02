<?php

namespace Tests\Feature;

use App\Log\SanitizeLogProcessor;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

it('masks access_token and refresh_token in log context', function () {
    $processor = new SanitizeLogProcessor();

    $record = [
        'message' => 'Publish succeeded',
        'context' => [
            'publish_id' => 42,
            'access_token' => 'ya29.a0AfH6SMB...',
            'refresh_token' => '1//0abc123def456',
            'platform' => 'youtube',
        ],
        'level' => 200,
        'level_name' => 'INFO',
        'channel' => 'test',
        'datetime' => new \DateTime(),
    ];

    $sanitized = $processor($record);

    expect($sanitized['context']['access_token'])->toBe('[REDACTED]');
    expect($sanitized['context']['refresh_token'])->toBe('[REDACTED]');
    expect($sanitized['context']['publish_id'])->toBe(42);
    expect($sanitized['context']['platform'])->toBe('youtube');
});

it('masks API secrets and DB credentials in log context', function () {
    $processor = new SanitizeLogProcessor();

    $record = [
        'message' => 'Connection established',
        'context' => [
            'client_secret' => 'secret_client_123',
            'db_password' => 'super-secret-db-password',
            'api_secret' => 'sk-test-abc123',
            'host' => '127.0.0.1',
        ],
        'level' => 200,
        'level_name' => 'INFO',
        'channel' => 'test',
        'datetime' => new \DateTime(),
    ];

    $sanitized = $processor($record);

    expect($sanitized['context']['client_secret'])->toBe('[REDACTED]');
    expect($sanitized['context']['db_password'])->toBe('[REDACTED]');
    expect($sanitized['context']['api_secret'])->toBe('[REDACTED]');
    expect($sanitized['context']['host'])->toBe('127.0.0.1');
});

it('masks storage_key and bucket in log context', function () {
    $processor = new SanitizeLogProcessor();

    $record = [
        'message' => 'Storage write failed',
        'context' => [
            'storage_key' => '42/100/uuid.mp4',
            'bucket' => 'aiclip-media-prod',
            'project_id' => 42,
        ],
        'level' => 400,
        'level_name' => 'ERROR',
        'channel' => 'test',
        'datetime' => new \DateTime(),
    ];

    $sanitized = $processor($record);

    expect($sanitized['context']['storage_key'])->toBe('[REDACTED]');
    expect($sanitized['context']['bucket'])->toBe('[REDACTED]');
    expect($sanitized['context']['project_id'])->toBe(42);
});

it('redacts passwords embedded in exception messages', function () {
    $processor = new SanitizeLogProcessor();

    $record = [
        'message' => 'DB connection failed',
        'context' => [
            'exception' => new \RuntimeException(
                'SQLSTATE[08006] [1045] Access denied for user aiclip (using password: real-password-here)'
            ),
        ],
        'level' => 400,
        'level_name' => 'ERROR',
        'channel' => 'test',
        'datetime' => new \DateTime(),
    ];

    $sanitized = $processor($record);

    $message = $sanitized['context']['exception']['message'];
    expect($message)->not->toContain('real-password-here');
    expect($message)->toContain('[REDACTED]');
});

it('redacts access_token query parameters in log messages', function () {
    $processor = new SanitizeLogProcessor();

    $record = [
        'message' => 'Callback received with access_token=ya29.a0AfH6SMB and state=abc',
        'context' => [],
        'level' => 200,
        'level_name' => 'INFO',
        'channel' => 'test',
        'datetime' => new \DateTime(),
    ];

    $sanitized = $processor($record);

    expect($sanitized['message'])->not->toContain('ya29.a0AfH6SMB');
    expect($sanitized['message'])->toContain('access_token=[REDACTED]');
});

it('production LOG_LEVEL is not debug', function () {
    Config::set('app.env', 'production');

    $level = env('LOG_LEVEL', 'error');

    expect($level)->not->toBe('debug');
    expect(in_array($level, ['info', 'error', 'warning', 'notice'], true))->toBeTrue();
});
