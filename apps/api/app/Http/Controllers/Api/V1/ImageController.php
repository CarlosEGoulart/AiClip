<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateImageRequest;
use App\Http\Resources\ImageCollection;
use App\Http\Resources\ImageResource;
use App\Jobs\GenerateImage;
use App\Models\Image;
use App\Models\Project;
use App\Services\StorageKeyBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImageController extends Controller
{
    /**
     * Validate, resolve an owned project, create the pending row, and
     * enqueue the queue job. Enqueue only — generation never runs inside
     * HTTP request processes (AGENTS.md §20).
     */
    public function generate(GenerateImageRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Ownership is server-controlled: non-owned and non-existent project
        // ids both return 404 without leaking which one failed.
        $project = Project::query()
            ->where('id', $validated['project_id'])
            ->where('user_id', $request->user()->id)
            ->first();

        if ($project === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $stylePreset = $validated['style_preset'] ?? GenerateImageRequest::DEFAULT_STYLE_PRESET;
        $width = (int) ($validated['width'] ?? GenerateImageRequest::DEFAULT_WIDTH);
        $height = (int) ($validated['height'] ?? GenerateImageRequest::DEFAULT_HEIGHT);

        $image = Image::create([
            'user_id' => $request->user()->id,
            'project_id' => $project->id,
            'prompt' => $validated['prompt'],
            'parameters' => [
                'style_preset' => $stylePreset,
                'width' => $width,
                'height' => $height,
            ],
            'storage_disk' => config('media.disk', 'media'),
            'storage_key' => StorageKeyBuilder::imageGeneration($project->id),
            'mime_type' => 'image/png',
            'size_bytes' => 0,
            'width' => $width,
            'height' => $height,
            'status' => ImageStatus::PENDING,
        ]);

        // The resource serializes the enqueue-time pending row without
        // refreshing after dispatch, so the 202 response always reports the
        // pending state; the terminal status arrives via subsequent GETs
        // (polling).
        GenerateImage::dispatch($image->id);

        return response()->json([
            'data' => new ImageResource($image),
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Paginated list of the authenticated user's images, newest first,
     * with an optional owned project filter.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Image::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id');

        if ($request->filled('project_id')) {
            $project = Project::query()
                ->where('id', $request->integer('project_id'))
                ->where('user_id', $request->user()->id)
                ->first();

            if ($project === null) {
                abort(Response::HTTP_NOT_FOUND);
            }

            $query->where('project_id', $project->id);
        }

        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        return response()->json([
            'data' => new ImageCollection($query->paginate($perPage)),
        ]);
    }

    /**
     * Single image, owner only (non-owner and non-existent ids both 404).
     */
    public function show(Request $request, Image $image): JsonResponse
    {
        if ($image->user_id !== $request->user()->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => new ImageResource($image),
        ]);
    }

    /**
     * Dual-gated download: a valid time-limited signature (issued only via
     * the image resource for generated images) resolves directly to a
     * 302 temporary storage URL; an invalid, expired, or tampered
     * signature is rejected 403 before any ownership or storage
     * information is exposed; an unsigned request falls back to session
     * ownership (guest 401, non-owner and non-existent ids 404). Only
     * generated images resolve — pending and failed rows 404 so storage
     * internals never leak for artifacts that do not exist. Storage
     * failures fail closed: 500 with a sanitized log.
     */
    public function download(Request $request, Image $image): RedirectResponse
    {
        if ($request->filled('signature')) {
            abort_unless($request->hasValidSignature(), Response::HTTP_FORBIDDEN);
        } else {
            $user = $request->user();

            if (! $user) {
                abort(Response::HTTP_UNAUTHORIZED);
            }

            if ($image->user_id !== $user->id) {
                abort(Response::HTTP_NOT_FOUND);
            }
        }

        $status = $image->status instanceof ImageStatus
            ? $image->status->value
            : $image->status;

        if ($status !== ImageStatus::GENERATED->value) {
            abort(Response::HTTP_NOT_FOUND);
        }

        try {
            $url = Storage::disk($image->storage_disk)->temporaryUrl(
                $image->storage_key,
                now()->addHour()
            );

            return redirect()->away($url);
        } catch (\Exception $e) {
            Log::error('Failed to generate signed image URL', [
                'image_id' => $image->id,
                'error' => $e->getMessage(),
            ]);

            abort(Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
