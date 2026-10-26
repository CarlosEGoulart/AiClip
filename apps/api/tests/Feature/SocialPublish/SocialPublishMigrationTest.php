<?php

namespace Tests\Feature\SocialPublish;

use Tests\TestCase;

uses(TestCase::class);

it('migration file exists', function () {
    $migrationPath = database_path('migrations/*create_social_publishes_table.php');

    $files = glob($migrationPath);

    expect($files)->not->toBeEmpty();
});

it('migration creates social_publishes table with required columns', function () {
    $migrationPath = database_path('migrations/*create_social_publishes_table.php');

    $files = glob($migrationPath);

    expect($files)->not->toBeEmpty();

    $content = file_get_contents($files[0]);

    expect($content)->toContain("Schema::create('social_publishes'");
    expect($content)->toContain("\$table->foreignId('user_id')->constrained()->cascadeOnDelete();");
    expect($content)->toContain("\$table->foreignId('social_connection_id')->constrained('social_connections')->cascadeOnDelete();");
    expect($content)->toContain("\$table->foreignId('clip_review_id')->constrained('clip_reviews')->cascadeOnDelete();");
    expect($content)->toContain("\$table->string('platform');");
    expect($content)->toContain("\$table->string('status');");
    expect($content)->toContain("\$table->string('external_video_id')->nullable();");
    expect($content)->toContain("\$table->string('video_url')->nullable();");
    expect($content)->toContain("\$table->string('title');");
    expect($content)->toContain("\$table->text('description')->nullable();");
    expect($content)->toContain("\$table->text('error_message')->nullable();");
    expect($content)->toContain("\$table->timestamps();");
});

it('migration creates required indexes', function () {
    $migrationPath = database_path('migrations/*create_social_publishes_table.php');

    $files = glob($migrationPath);

    expect($files)->not->toBeEmpty();

    $content = file_get_contents($files[0]);

    expect($content)->toContain("\$table->index(['user_id', 'status']);");
    expect($content)->toContain("\$table->index(['social_connection_id']);");
    expect($content)->toContain("\$table->index(['clip_review_id']);");
});
