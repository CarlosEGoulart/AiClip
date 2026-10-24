<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectSocialPlatformRequest;
use App\Http\Resources\SocialConnectionCollection;
use App\Models\SocialConnection;
use App\Services\SocialConnectionManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SocialConnectionController extends Controller
{
    public function __construct(
        private SocialConnectionManager $manager
    ) {}

    /**
     * Display a listing of the authenticated user's social connections.
     */
    public function index(Request $request): JsonResponse
    {
        $connections = $request->user()
            ->socialConnections()
            ->orderByDesc('created_at')
            ->paginate(15);

        return SocialConnectionCollection::make($connections)->response();
    }

    /**
     * Initiate a new social connection.
     */
    public function connect(ConnectSocialPlatformRequest $request, string $platform): JsonResponse
    {
        $validPlatforms = config('social.platforms', ['youtube', 'instagram', 'tiktok']);

        if (! in_array($platform, $validPlatforms)) {
            return response()->json([
                'message' => 'Invalid platform',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Check if connection already exists
        $existing = $request->user()
            ->socialConnections()
            ->where('platform', $platform)
            ->whereIn('status', ['connected', 'expired'])
            ->exists();

        if ($existing) {
            return response()->json([
                'message' => 'Connection already exists for this platform',
            ], Response::HTTP_CONFLICT);
        }

        $result = $this->manager->initiateConnection($request->user(), $platform);

        return response()->json([
            'data' => [
                'authorization_url' => $result->authorizationUrl,
                'state' => $result->state,
                'connection_id' => $result->connectionId,
            ],
        ]);
    }

    /**
     * Handle the OAuth callback.
     * Does not require authentication - finds pending connection by state.
     */
    public function callback(Request $request, string $platform): RedirectResponse
    {
        $validPlatforms = config('social.platforms', ['youtube', 'instagram', 'tiktok']);

        if (! in_array($platform, $validPlatforms)) {
            return redirect('/connections?error=invalid_platform');
        }

        $state = $request->query('state');
        $code = $request->query('code');

        if (! $state || ! $code) {
            return redirect('/connections?error=missing_parameters');
        }

        try {
            $result = $this->manager->handleCallback(
                $platform,
                $state,
                $code
            );

            return redirect("/connections?connected={$platform}");
        } catch (ModelNotFoundException) {
            Log::warning('Social connection callback: invalid or expired state', [
                'platform' => $platform,
            ]);

            return redirect('/connections?error=invalid_state');
        } catch (\Throwable $e) {
            Log::error('Social connection callback failed', [
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);

            return redirect('/connections?error=callback_failed');
        }
    }

    /**
     * Disconnect a social connection.
     */
    public function disconnect(Request $request, SocialConnection $connection): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeOwnership($request, $connection);

        $this->manager->disconnect($connection);

        return response()->noContent();
    }

    /**
     * Get connection status.
     */
    public function status(Request $request, SocialConnection $connection): JsonResponse
    {
        $this->authorizeOwnership($request, $connection);

        return response()->json([
            'data' => [
                'id' => $connection->id,
                'status' => $connection->status,
                'last_error' => $connection->last_error,
                'access_token_expires_at' => $connection->access_token_expires_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Ensure the authenticated user owns the connection.
     * Returns 404 if not found (hides existence from non-owners).
     */
    protected function authorizeOwnership(Request $request, SocialConnection $connection): void
    {
        if ($connection->user_id !== $request->user()->id) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }
}
