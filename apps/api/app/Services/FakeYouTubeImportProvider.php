<?php

namespace App\Services;

use App\Contracts\YouTubeImportProvider;

/**
 * Deterministic, model-free YouTube import provider (AGENTS.md §21).
 *
 * CI-safe: no network access, no YouTube API credentials, no model download.
 * Recognizes youtube.com/watch?v=VIDEO_ID and youtu.be/VIDEO_ID URL patterns
 * and returns fixed fixture metadata. Real provider implementations replace
 * this binding in a future slice without any domain changes.
 */
class FakeYouTubeImportProvider implements YouTubeImportProvider
{
    public const PLATFORM = 'youtube';

    // youtube.com/watch?v=VIDEO_ID or youtu.be/VIDEO_ID
    // The video id is 11 characters: [A-Za-z0-9_-]
    private const URL_PATTERN = '#^(?:https?://)?(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]{11})(?:[?&].*)?$#';

    public function getPlatform(): string
    {
        return self::PLATFORM;
    }

    public function validateUrl(string $url): bool
    {
        return (bool) preg_match(self::URL_PATTERN, $url);
    }

    public function extractMetadata(string $url): array
    {
        if (! $this->validateUrl($url)) {
            return [];
        }

        preg_match(self::URL_PATTERN, $url, $matches);

        return [
            'video_id' => $matches[1] ?? '',
            'title' => 'Fake YouTube Video',
            'duration_seconds' => 300,
            'thumbnail_url' => 'https://i.ytimg.com/vi/' . ($matches[1] ?? '') . '/hqdefault.jpg',
        ];
    }
}
