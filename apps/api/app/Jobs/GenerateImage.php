<?php

namespace App\Jobs;

use App\Contracts\ImageGenerationProvider;
use App\Enums\ImageStatus;
use App\Exceptions\InvalidInputException;
use App\Models\Image;
use App\Services\ImageResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Queue job that generates an image artifact for a pending Image row.
 *
 * Follows the RenderMediaClip topology: the queue job is the boundary that
 * invokes the provider seam (heavy generation never runs inside HTTP
 * request processes, AGENTS.md §20), Laravel strictly validates the
 * provider result, writes the storage artifact, and owns PostgreSQL
 * persistence inside a database transaction. The job payload carries only
 * the image id; ownership is re-validated from persisted rows.
 */
class GenerateImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retry.
     */
    public int $backoff = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $imageId,
        protected ?ImageGenerationProvider $provider = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $image = Image::query()->find($this->imageId);

        if ($image === null) {
            throw new InvalidInputException('invalid_input');
        }

        // Re-validate ownership from persisted rows: the image's user must
        // still own the image's project. On mismatch, skip without any state
        // change, storage write, or provider call.
        $project = $image->project()->first();

        if ($project === null || $project->user_id !== $image->user_id) {
            Log::warning('GenerateImage: image ownership mismatch; skipping generation.', [
                'image_id' => $image->id,
            ]);

            return;
        }

        // Idempotency: only pending rows generate; terminal rows return early.
        if ($image->status !== ImageStatus::PENDING) {
            return;
        }

        $provider = $this->provider ?? app(ImageGenerationProvider::class);

        $parameters = $image->parameters ?? [];
        $stylePreset = is_string($parameters['style_preset'] ?? null)
            ? $parameters['style_preset']
            : 'photorealistic';

        try {
            $result = $provider->generate($image->prompt, $stylePreset, $image->width, $image->height);

            $this->validateResult($result, $image->width, $image->height);
        } catch (\Throwable $exception) {
            // Classified provider/validation failure: sanitized reason only —
            // no provider internals and no raw prompt content in log entries.
            Log::warning('GenerateImage: image generation failed.', [
                'image_id' => $image->id,
                'reason' => $exception instanceof InvalidInputException ? 'invalid_result' : 'generation_failed',
            ]);

            DB::transaction(function () use ($image): void {
                $image->status = ImageStatus::FAILED;
                $image->save();
            });

            return;
        }

        // Write the artifact. An unexpected storage exception propagates so
        // the queue retries (tries = 3, backoff = 5); the row remains
        // pending — no false success.
        Storage::disk($image->storage_disk)->put($image->storage_key, $result->data);

        DB::transaction(function () use ($image, $result): void {
            $image->status = ImageStatus::GENERATED;
            $image->mime_type = $result->mimeType;
            $image->size_bytes = $result->sizeBytes();
            $image->width = $result->width;
            $image->height = $result->height;
            $image->save();
        });
    }

    /**
     * Strict Laravel-side validation of the provider result before any
     * persistence: non-empty data, PNG signature for image/png, positive
     * dimensions equal to the requested values, and a positive byte size.
     */
    private function validateResult($result, int $requestedWidth, int $requestedHeight): void
    {
        if (! $result instanceof ImageResult) {
            throw new InvalidInputException('invalid_input');
        }

        if ($result->data === '' || $result->sizeBytes() < 1) {
            throw new InvalidInputException('invalid_input');
        }

        if ($result->mimeType !== 'image/png') {
            throw new InvalidInputException('invalid_input');
        }

        if (! str_starts_with($result->data, "\x89PNG\r\n\x1a\n")) {
            throw new InvalidInputException('invalid_input');
        }

        if ($result->width < 1 || $result->height < 1
            || $result->width !== $requestedWidth || $result->height !== $requestedHeight) {
            throw new InvalidInputException('invalid_input');
        }

        if ($result->sizeBytes() !== strlen($result->data)) {
            throw new InvalidInputException('invalid_input');
        }
    }

    /**
     * The unique ID of the job (used for idempotent dispatch).
     */
    public function uniqueId(): string
    {
        return "generate-image:{$this->imageId}";
    }
}
