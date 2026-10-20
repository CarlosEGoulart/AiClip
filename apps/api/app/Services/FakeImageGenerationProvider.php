<?php

namespace App\Services;

use App\Contracts\ImageGenerationProvider;

/**
 * Deterministic, model-free image generation provider (AGENTS.md §21).
 *
 * This is the slice's bound production implementation of the provider
 * contract: it synthesizes a real, decodable PNG artifact with pure PHP
 * (hash-seeded pixel rows + zlib-deflated IDAT) — no network access, no
 * subprocess, and no model download. Identical inputs always produce
 * byte-identical artifacts; any differing input (prompt, style preset, or
 * dimensions) produces different artifact bytes. The model-backed provider
 * (Flux/SDXL via the Python worker CLI) is a future slice and replaces this
 * binding without any domain changes.
 */
class FakeImageGenerationProvider implements ImageGenerationProvider
{
    /**
     * Fixed style preset catalog (slice constant).
     *
     * @var array<int, array{id: string, label: string}>
     */
    public const STYLE_PRESETS = [
        ['id' => 'photorealistic', 'label' => 'Photorealistic'],
        ['id' => 'illustration', 'label' => 'Illustration'],
        ['id' => 'cinematic', 'label' => 'Cinematic'],
        ['id' => 'digital_art', 'label' => 'Digital Art'],
    ];

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * Fixed zlib compression level so identical inputs deflate identically.
     */
    private const ZLIB_LEVEL = 6;

    public function getStylePresets(): array
    {
        return self::STYLE_PRESETS;
    }

    public function generate(string $prompt, string $stylePreset, int $width, int $height): ImageResult
    {
        return new ImageResult(
            data: $this->synthesizePng($prompt, $stylePreset, $width, $height),
            mimeType: 'image/png',
            width: $width,
            height: $height,
        );
    }

    /**
     * Build a valid PNG: signature, IHDR with the exact requested dimensions,
     * IDAT (zlib-deflated grayscale scanlines), and IEND.
     *
     * Every scanline's pixel bytes are derived deterministically from a
     * SHA-256 hash of (prompt, style preset, width, height) combined with
     * the row index, so the artifact is reproducible and input-sensitive.
     */
    private function synthesizePng(string $prompt, string $stylePreset, int $width, int $height): string
    {
        $seed = hash('sha256', $prompt."\0".$stylePreset."\0".$width."\0".$height);

        $raw = '';
        for ($row = 0; $row < $height; $row++) {
            $pattern = hash('sha256', $seed.$row, true);
            $line = str_repeat($pattern, intdiv($width, 32) + 1);

            // Filter byte 0 (None) followed by the row's grayscale pixels.
            $raw .= "\x00".substr($line, 0, $width);
        }

        // IHDR: width, height, bit depth 8, color type 0 (grayscale),
        // default compression, default filter method, no interlace.
        $ihdr = pack('N', $width)
            .pack('N', $height)
            ."\x08\x00\x00\x00\x00";

        return self::PNG_SIGNATURE
            .$this->chunk('IHDR', $ihdr)
            .$this->chunk('IDAT', (string) gzdeflate($raw, self::ZLIB_LEVEL))
            .$this->chunk('IEND', '');
    }

    /**
     * Encode a PNG chunk: length, type, data, CRC-32 over type + data.
     */
    private function chunk(string $type, string $data): string
    {
        $body = $type.$data;

        return pack('N', strlen($data)).$body.pack('N', crc32($body));
    }
}
