<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    /**
     * Display a listing of the authenticated user's projects.
     */
    public function index(Request $request): JsonResponse
    {
        $projects = $request->user()
            ->projects()
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => ProjectResource::collection($projects),
        ]);
    }

    /**
     * Store a newly created project.
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = $request->user()->projects()->create([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
        ]);

        return response()->json([
            'data' => new ProjectResource($project),
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified project (only if owned by user).
     */
    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorizeOwnership($request, $project);

        return response()->json([
            'data' => new ProjectResource($project),
        ]);
    }

    /**
     * Remove the specified project (only if owned by user).
     *
     * Storage files for all associated media assets are deleted first.
     * If any storage deletion fails, the entire operation is aborted with
     * a 500 response and the project + media records are preserved.
     */
    public function destroy(Request $request, Project $project): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeOwnership($request, $project);

        // Attempt to delete all media storage files before deleting the
        // project.  If any deletion fails, abort the entire operation so
        // that the project and media records are preserved.
        $storageFailed = false;

        try {
            $disk = Storage::disk(config('media.disk', 'media'));

            foreach ($project->mediaAssets as $media) {
                try {
                    $disk->delete($media->storage_key);
                } catch (\Exception $e) {
                    Log::error("Storage deletion failed during project cleanup: {$project->id}", [
                        'error' => $e->getMessage(),
                        'media_id' => $media->id,
                    ]);
                    $storageFailed = true;
                }
            }
        } catch (\Exception $e) {
            Log::warning("Storage disk unavailable during project cleanup: {$project->id}", [
                'error' => $e->getMessage(),
            ]);
        }

        if ($storageFailed) {
            return response()->json([
                'message' => 'Unable to complete media storage operation.',
                'request_id' => Str::uuid(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $project->delete();

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
