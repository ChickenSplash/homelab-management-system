{{-- The current account, once signed in. --}}
@extends('accounts.layout')

@section('title', 'Profile')

@section('content')
    <h1>{{ $user->display_name }}</h1>
    <p class="muted" data-testid="signed-in-as">
        Signed in{{ $user->is_guest ? ' as a guest' : ' as '.$user->email }}
    </p>

    @if (session('password_changed'))
        <p class="small profile-status" role="status">Your password has been changed.</p>
    @endif

    @unless ($user->is_guest)
        <details class="card profile-settings" @if ($errors->any()) open @endif>
            <summary>Change password</summary>
            <form method="post" action="{{ route('accounts.password.update') }}">
                @csrf
                @error('form') <p class="note" role="alert">{{ $message }}</p> @enderror
                @include('accounts.field', ['name' => 'current_password', 'label' => 'Current password', 'type' => 'password', 'autocomplete' => 'current-password'])
                @include('accounts.field', ['name' => 'password', 'label' => 'New password', 'type' => 'password', 'autocomplete' => 'new-password', 'minlength' => 8])
                @include('accounts.field', ['name' => 'password_confirmation', 'label' => 'Confirm new password', 'type' => 'password', 'autocomplete' => 'new-password', 'minlength' => 8])
                <button type="submit">Change password</button>
            </form>
        </details>
    @endunless

    <form method="post" action="/logout" class="card">
        @csrf
        @if ($user->is_guest)
            <p class="small">Logging out deletes this guest account in every app, with its playlists and liked songs. It can't be recovered.</p>
            <button type="submit" class="danger">Log out and delete guest account</button>
        @else
            <button type="submit" class="secondary">Log out</button>
        @endif
    </form>

    <p class="footer"><a href="/terms" data-navigate>Terms and privacy</a></p>
@endsection
