{{-- The sign-in page: one tab per way in. Each tab is its own URL, carrying ?return= along. --}}
@extends('accounts.layout')

@php
    $tabs = ['login' => 'Log in', 'register' => 'Register', 'guest' => 'Join as guest'];
    $query = $return ? '?'.http_build_query(['return' => $return]) : '';
@endphp

@section('title', $tabs[$tab])

@section('content')
    <div class="card">
        <nav class="tabs" aria-label="Sign in">
            @foreach ($tabs as $id => $label)
                <a href="/{{ $id }}{{ $query }}" data-navigate @if ($id === $tab) aria-current="page" @endif>{{ $label }}</a>
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

    <p class="footer"><a href="/terms" data-navigate>Terms and privacy</a></p>
@endsection
