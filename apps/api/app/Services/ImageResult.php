<?php

namespace App\Services;

final readonly class ImageResult
{
    public function __construct(
        public string $data,
        public string $mimeType,
        public int $width,
        public int $height,
    ) {}

    public function sizeBytes(): int
    {
        return strlen($this->data);
    }
}
