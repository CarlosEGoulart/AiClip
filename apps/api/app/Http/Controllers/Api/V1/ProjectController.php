<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
     * Update the specified project (only if owned by user).
     */
    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $this->authorizeOwnership($request, $project);

        $project->update([
            'name' => $request->validated('name'),
        ]);

        return response()->json([
            'data' => new ProjectResource($project->fresh()),
        ]);
    }

    /**
     * Remove the specified project (only if owned by user).
     */
    public function destroy(Request $request, Project $project): Response
    {
        $this->authorizeOwnership($request, $project);

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
