<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
        ]);

        Auth::guard('web')->login($user);

        return response()->json([
            'user' => new UserResource($user),
        ], Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $throttleKey = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => ['Too many login attempts. Please try again in '.$seconds.' seconds.'],
            ])->status(Response::HTTP_TOO_MANY_REQUESTS);
        }

        $credentials = $request->only('email', 'password');

        if (! Auth::guard('web')->attempt($credentials)) {
            RateLimiter::hit($throttleKey, 5 * 60);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        RateLimiter::clear($throttleKey);

        $request->session()->regenerate();

        return response()->json([
            'user' => new UserResource(Auth::guard('web')->user()),
        ]);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'user' => new UserResource($user),
        ]);
    }

    protected function throttleKey(Request $request): string
    {
        return strtolower($request->input('email', '')).'|'.$request->ip();
    }
}
