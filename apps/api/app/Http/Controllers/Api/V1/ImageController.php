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
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
}
