<?php

namespace App\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class StorageKeyBuilder
{
    public static function renderClip(
        int $projectId,
        int $mediaAssetId,
        int $candidateIndex,
        string $renderProfileVersion,
    ): string {
        if ($projectId < 1 || $mediaAssetId < 1 || $candidateIndex < 0) {
            throw new InvalidArgumentException('Invalid render storage identity.');
        }

        if ($renderProfileVersion === ''
            || preg_match('/\A[A-Za-z0-9._-]+\z/', $renderProfileVersion) !== 1) {
            throw new InvalidArgumentException('Invalid render profile version.');
        }

        return sprintf(
            'projects/%d/renders/%d/%d/%s/%s.mp4',
            $projectId,
            $mediaAssetId,
            $candidateIndex,
            $renderProfileVersion,
            Str::uuid()->toString(),
        );
    }
}
