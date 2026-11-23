<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreYouTubeImportRequest;
use App\Jobs\YouTubeImportJob;
use App\Models\MediaAsset;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class YouTubeImportController extends Controller
{
    /**
     * Validate a YouTube URL, create a queued MediaAsset row, and enqueue
     * the YouTube import job. Enqueue only — no file bytes enter the HTTP
     * request process and no download happens here (AGENTS.md §20).
     */
    public function import(StoreYouTubeImportRequest $request, Project $project): JsonResponse
    {
        // Ownership is server-controlled: non-owner/non-existent returns 404.
        $user = $request->user();

        if (! $user || $project->user_id !== $user->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        $url = $request->validated('url');

        // Generate storage key: {user_id}/{project_id}/{uuid}.mp4
        // Clients never choose any segment.
        $storageKey = "{$user->id}/{$project->id}/".Str::uuid().'.mp4';

        $mediaAsset = MediaAsset::create([
            'project_id' => $project->id,
            'original_name' => 'youtube-import',
            'storage_disk' => config('media.disk', 'media'),
            'storage_key' => $storageKey,
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
            'status' => MediaAsset::PROCESSING_QUEUED,
            'processing_status' => MediaAsset::PROCESSING_QUEUED,
        ]);

        // Dispatch the import job (enqueue only).
        YouTubeImportJob::dispatch(
            $mediaAsset->id,
            $url,
            (string) Str::uuid(),
            $user->id,
        );

        Log::info('YouTube import enqueued.', [
            'media_asset_id' => $mediaAsset->id,
            'project_id' => $project->id,
        ]);

        return response()->json([
            'data' => [
                'media_asset_id' => $mediaAsset->id,
                'status' => 'queued',
            ],
        ], Response::HTTP_ACCEPTED);
    }
}
