<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Accounts;
use App\Support\DisplayName;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * The sign-in site: one place to log in, register or join as a guest, for every app. The
 * session cookie is set on the parent domain (SESSION_DOMAIN), so the apps see the same
 * session; they send signed-out visitors here with ?return=<where they were>.
 */
class AccountsController
{
    public function home(Request $request): View|RedirectResponse
    {
        if (! Auth::guard('web')->check()) {
            return redirect('/login');
        }

        return view('accounts.home', ['user' => Auth::guard('web')->user(), 'apps' => Accounts::apps()]);
    }

    public function showLogin(Request $request): View|RedirectResponse
    {
        return $this->form($request, 'login');
    }

    public function showRegister(Request $request): View|RedirectResponse
    {
        return $this->form($request, 'register');
    }

    public function showGuest(Request $request): View|RedirectResponse
    {
        return $this->form($request, 'guest');
    }

    public function login(Request $request): RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return $this->onward($request);
        }

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();

        return $this->onward($request);
    }

    public function register(Request $request): RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return $this->onward($request);
        }

        $validated = $request->validate([
            'display_name' => DisplayName::rules(),
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'terms' => ['accepted'],
        ], $this->termsMessage());

        $user = User::create(collect($validated)->except('terms')->all());

        return $this->signIn($request, $user, remember: true);
    }

    public function guest(Request $request): RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return $this->onward($request);
        }

        $validated = $request->validate([
            'display_name' => DisplayName::rules(),
            'terms' => ['accepted'],
        ], $this->termsMessage());

        $user = User::create(['display_name' => $validated['display_name'], 'is_guest' => true]);

        // No "remember me": a guest has no way back in, so the session is the account.
        return $this->signIn($request, $user, remember: false);
    }

    public function logout(Request $request): RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            Accounts::signOut($request);
        }

        return redirect('/login');
    }

    public function terms(): View
    {
        return view('accounts.terms');
    }

    /** Already signed in, a form only gets in the way: go straight back (or to the launcher). */
    private function form(Request $request, string $tab): View|RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return $this->onward($request);
        }

        return view("accounts.{$tab}", ['tab' => $tab, 'return' => Accounts::safeReturn($request->query('return'))]);
    }

    private function signIn(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        return $this->onward($request);
    }

    /** Back to the app that sent you here, if it's one of ours; otherwise the launcher. */
    private function onward(Request $request): RedirectResponse
    {
        $return = Accounts::safeReturn($request->input('return'));

        return $return ? redirect()->away($return) : redirect('/');
    }

    /** @return array<string, string> */
    private function termsMessage(): array
    {
        return ['terms.accepted' => 'You need to be 18 or over and agree to the terms.'];
    }
}
