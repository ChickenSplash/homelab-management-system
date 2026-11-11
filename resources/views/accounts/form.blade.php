{{-- The sign-in page: one tab per way in. Each tab is its own URL, carrying ?return= along. --}}
@extends('accounts.layout')

@php
    $tabs = ['login' => 'Log in', 'register' => 'Register', 'guest' => 'Join as guest'];
    $query = $return ? '?'.http_build_query(['return' => $return]) : '';
@endphp

@section('title', $tabs[$tab])

@section('content')
    <h1>Homelab accounts</h1>
    <p class="muted">One account for Chat, Music and whatever comes next.</p>

    <div class="card">
        <nav class="tabs" aria-label="Sign in">
            @foreach ($tabs as $id => $label)
                <a href="/{{ $id }}{{ $query }}" @if ($id === $tab) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        <form method="post" action="/{{ $tab }}">
            @csrf
            @if ($return)
                <input type="hidden" name="return" value="{{ $return }}">
            @endif

            @error('form')
                <p class="note" role="alert">{{ $message }}</p>
            @enderror

            @yield('fields')
        </form>
    </div>

    <p class="footer"><a href="/terms">Terms and privacy</a></p>
@endsection
