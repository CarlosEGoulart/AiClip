<?php

namespace App\Contracts;

/**
 * Provider seam for YouTube URL import.
 *
 * Decouples the application from any specific YouTube metadata/download
 * library (AGENTS.md §21). The contract only validates a YouTube URL and
 * extracts deterministic metadata. Heavy download/processing never runs
 * inside an HTTP request process — that belongs to the queue job.
 */
interface YouTubeImportProvider
{
    /**
     * Validate that a URL is a supported YouTube video URL.
     */
    public function validateUrl(string $url): bool;

    /**
     * Extract metadata from a YouTube URL.
     *
     * @return array<string, mixed> Deterministic metadata such as
     *   video_id, title, duration_seconds, and thumbnail_url.
     */
    public function extractMetadata(string $url): array;
}
