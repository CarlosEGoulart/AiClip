<?php

namespace App\Contracts;

use App\Services\ImageResult;

interface ImageGenerationProvider
{
    /**
     * Fixed style preset catalog.
     *
     * @return array<int, array{id: string, label: string}>
     */
    public function getStylePresets(): array;

    /**
     * Generate an image artifact for the given request.
     */
    public function generate(string $prompt, string $stylePreset, int $width, int $height): ImageResult;
}
