<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

// Session (cookie) auth for browser apps on SANCTUM_STATEFUL_DOMAINS.
class AuthController
{
    /** @return array<int, mixed> */
    private function displayNameRules(): array
    {
        // Any name at all, as long as it has something visible and no control characters.
        return ['required', 'string', 'max:32', 'regex:/\S/u', 'not_regex:/\p{C}/u'];
    }

    public function register(Request $request): JsonResponse
    {
        $this->ensureSignedOut($request);

        $validated = $request->validate([
            'display_name' => $this->displayNameRules(),
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create($validated);

        return $this->signIn($request, $user, remember: true);
    }

    public function login(Request $request): JsonResponse
    {
        $this->ensureSignedOut($request);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();

        return (new UserResource(Auth::guard('web')->user()))->response();
    }

    public function guest(Request $request): JsonResponse
    {
        $this->ensureSignedOut($request);

        $validated = $request->validate(['display_name' => $this->displayNameRules()]);

        $user = User::create([...$validated, 'is_guest' => true]);

        // No "remember me": a guest has no way back in, so the session is the account.
        return $this->signIn($request, $user, remember: false);
    }

    public function logout(Request $request): Response
    {
        $user = $request->user();

        // A script signed in with a bearer token gives up just that token.
        if (! $request->hasSession()) {
            $user->currentAccessToken()->delete();

            return response()->noContent();
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Nothing could ever sign back in as this guest.
        if ($user->is_guest) {
            $user->delete();
        }

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function update(Request $request): UserResource
    {
        $validated = $request->validate([
            'display_name' => ['sometimes', ...$this->displayNameRules()],
        ]);

        $request->user()->update($validated);

        return new UserResource($request->user());
    }

    public function updatePassword(Request $request): Response
    {
        abort_if($request->user()->is_guest, 403, 'Guests have no password.');

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update(['password' => $validated['password']]);

        return response()->noContent();
    }

    private function signIn(Request $request, User $user, bool $remember): JsonResponse
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    /**
     * Signing in needs a session, which only requests from SANCTUM_STATEFUL_DOMAINS get.
     * Switching accounts goes through logout, so a guest is never left orphaned behind a new login.
     */
    private function ensureSignedOut(Request $request): void
    {
        abort_unless($request->hasSession(), 400, 'Signing in needs a browser app on an allowed domain.');
        abort_if(Auth::guard('web')->check(), 409, 'Already signed in.');
    }
}
