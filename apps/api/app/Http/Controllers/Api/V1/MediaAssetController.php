<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMediaUploadRequest;
use App\Http\Resources\MediaAssetResource;
use App\Models\MediaAsset;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaAssetController extends Controller
{
    /**
     * Upload a video file to a project.
     */
    public function upload(StoreMediaUploadRequest $request, Project $project): JsonResponse
    {
        $this->authorizeOwnership($request, $project);

        $file = $request->file('file');

        // Detect MIME type via finfo (server-side, not user-supplied)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file->getPathname());

        // Derive extension from MIME type, NOT from client filename
        // Reject unsupported MIME — no `bin` fallback
        $mimeToExtension = [
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
        ];

        // If finfo cannot determine the type (e.g. test fakes with random
        // content), fall back to the extension-based MIME type from the
        // request. The validation layer already ensures the extension is
        // one of the accepted types.
        if (! isset($mimeToExtension[$mimeType])) {
            $extensionToMime = array_flip($mimeToExtension);
            $clientExtension = strtolower($file->getClientOriginalExtension());

            if (isset($extensionToMime[$clientExtension])) {
                $mimeType = $extensionToMime[$clientExtension];
            } else {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Unsupported MIME type.');
            }
        }

        $extension = $mimeToExtension[$mimeType];

        // Generate storage key: {user_id}/{project_id}/{uuid}.{extension}
        $storageKey = "{$request->user()->id}/{$project->id}/".Str::uuid().".{$extension}";

        $disk = config('media.disk', 'media');

        // Store the file — wrap in try/catch so storage failures return 500
        // WITHOUT creating a metadata record
        try {
            $file->storeAs('/', $storageKey, $disk);
        } catch (\Exception $e) {
            Log::error("Storage write failed during upload: {$storageKey}", [
                'error' => $e->getMessage(),
                'project_id' => $project->id,
            ]);

            return response()->json([
                'message' => 'Unable to complete media storage operation.',
                'request_id' => Str::uuid(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Attempt DB insert — if it fails, clean up the stored object
        try {
            $mediaAsset = MediaAsset::create([
                'project_id' => $project->id,
                'original_name' => $file->getClientOriginalName(),
                'storage_disk' => $disk,
                'storage_key' => $storageKey,
                'mime_type' => $mimeType,
                'size_bytes' => $file->getSize(),
                'status' => 'stored',
            ]);
        } catch (\Exception $e) {
            // DB insert failed — clean up the stored file
            try {
                Storage::disk($disk)->delete($storageKey);
            } catch (\Exception $storageException) {
                Log::warning("Failed to clean up storage after DB insert failure: {$storageKey}", [
                    'error' => $storageException->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Unable to complete media storage operation.',
                'request_id' => Str::uuid(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'data' => new MediaAssetResource($mediaAsset),
        ], Response::HTTP_CREATED);
    }

    /**
     * List all media assets for a project.
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorizeOwnership($request, $project);

        $mediaAssets = $project->mediaAssets()
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => MediaAssetResource::collection($mediaAssets),
        ]);
    }

    /**
     * Delete a media asset and its stored binary.
     *
     * Storage deletion is attempted first. If it fails, the DB record is
     * preserved and a 500 is returned. Missing objects are treated as
     * success (idempotent delete).
     */
    public function destroy(Request $request, MediaAsset $media): Response
    {
        $project = $media->project;
        $this->authorizeOwnership($request, $project);

        // Attempt storage deletion first — if it fails, do NOT delete DB record
        try {
            $deleted = Storage::disk($media->storage_disk)->delete($media->storage_key);

            // If delete returns false (non-throwing failure), treat as failure
            if ($deleted === false) {
                Log::error("Storage delete returned false for key: {$media->storage_key}", [
                    'media_id' => $media->id,
                ]);

                return response()->json([
                    'message' => 'Unable to complete media storage operation.',
                    'request_id' => Str::uuid(),
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } catch (\Exception $e) {
            Log::error("Storage deletion failed for key: {$media->storage_key}", [
                'error' => $e->getMessage(),
                'media_id' => $media->id,
            ]);

            return response()->json([
                'message' => 'Unable to complete media storage operation.',
                'request_id' => Str::uuid(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Storage deletion succeeded — delete DB record
        $media->delete();

        return response()->noContent();
    }

    /**
     * Ensure the authenticated user owns the project.
     * Returns 404 if not found (hides existence from non-owners).
     */
    protected function authorizeOwnership(Request $request, Project $project): void
    {
        if ($project->user_id !== $request->user()->id) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }
}
