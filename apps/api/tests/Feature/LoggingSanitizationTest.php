<?php

namespace Tests\Feature;

use App\Log\SanitizeLogProcessor;
use Illuminate\Support\Facades\Config;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

uses(TestCase::class);

it('masks access_token and refresh_token in log context', function () {
    $processor = new SanitizeLogProcessor();

    $record = new LogRecord(
        new \DateTimeImmutable(),
        'test',
        Level::Info,
        'Publish succeeded',
        [
            'publish_id' => 42,
            'access_token' => 'ya29.a0AfH6SMB...',
            'refresh_token' => '1//0abc123def456',
            'platform' => 'youtube',
        ]
    );

    $sanitized = $processor($record);

    $context = $sanitized->context;
    expect($context['access_token'])->toBe('[REDACTED]');
    expect($context['refresh_token'])->toBe('[REDACTED]');
    expect($context['publish_id'])->toBe(42);
    expect($context['platform'])->toBe('youtube');
});

it('masks API secrets and DB credentials in log context', function () {
    $processor = new SanitizeLogProcessor();

    $record = new LogRecord(
        new \DateTimeImmutable(),
        'test',
        Level::Info,
        'Connection established',
        [
            'client_secret' => 'secret_client_123',
            'db_password' => 'super-secret-db-password',
            'api_secret' => 'sk-test-abc123',
            'host' => '127.0.0.1',
        ]
    );

    $sanitized = $processor($record);

    $context = $sanitized->context;
    expect($context['client_secret'])->toBe('[REDACTED]');
    expect($context['db_password'])->toBe('[REDACTED]');
    expect($context['api_secret'])->toBe('[REDACTED]');
    expect($context['host'])->toBe('127.0.0.1');
});

it('masks storage_key and bucket in log context', function () {
    $processor = new SanitizeLogProcessor();

    $record = new LogRecord(
        new \DateTimeImmutable(),
        'test',
        Level::Error,
        'Storage write failed',
        [
            'storage_key' => '42/100/uuid.mp4',
            'bucket' => 'aiclip-media-prod',
            'project_id' => 42,
        ]
    );

    $sanitized = $processor($record);

    $context = $sanitized->context;
    expect($context['storage_key'])->toBe('[REDACTED]');
    expect($context['bucket'])->toBe('[REDACTED]');
    expect($context['project_id'])->toBe(42);
});

it('redacts passwords embedded in exception messages', function () {
    $processor = new SanitizeLogProcessor();

    $record = new LogRecord(
        new \DateTimeImmutable(),
        'test',
        Level::Error,
        'DB connection failed',
        [
            'exception' => new \RuntimeException(
                'SQLSTATE[08006] [1045] Access denied for user aiclip (using password: real-password-here)'
            ),
        ]
    );

    $sanitized = $processor($record);

    $context = $sanitized->context;
    $message = $context['exception']['message'];
    expect($message)->not->toContain('real-password-here');
    expect($message)->toContain('[REDACTED]');
});

it('redacts access_token query parameters in log messages', function () {
    $processor = new SanitizeLogProcessor();

    $record = new LogRecord(
        new \DateTimeImmutable(),
        'test',
        Level::Info,
        'Callback received with access_token=ya29.a0AfH6SMB and state=abc',
        []
    );

    $sanitized = $processor($record);

    expect($sanitized->message)->not->toContain('ya29.a0AfH6SMB');
    expect($sanitized->message)->toContain('access_token=[REDACTED]');
});

it('production LOG_LEVEL is not debug', function () {
    Config::set('app.env', 'production');

    $level = env('LOG_LEVEL', 'error');

    expect($level)->not->toBe('debug');
    expect(in_array($level, ['info', 'error', 'warning', 'notice'], true))->toBeTrue();
});
