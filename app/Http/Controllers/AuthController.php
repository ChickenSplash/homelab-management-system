<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Support\Accounts;
use App\Support\DisplayName;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rules\Password;

// The signed-in account, for the apps. Signing in happens on the sign-in site (AccountsController).
class AuthController
{
    public function logout(Request $request): Response
    {
        $user = $request->user();

        // A script signed in with a bearer token gives up just that token.
        if (! $request->hasSession()) {
            $user->currentAccessToken()->delete();

            return response()->noContent();
        }

        Accounts::signOut($request);

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function update(Request $request): UserResource
    {
        $validated = $request->validate([
            'display_name' => ['sometimes', ...DisplayName::rules()],
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
}
