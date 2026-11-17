{{-- The launcher, once signed in. --}}
@extends('accounts.layout')

@section('title', 'Profile')

@section('content')
    <h1>{{ $user->display_name }}</h1>
    <p class="muted" data-testid="signed-in-as">
        Signed in{{ $user->is_guest ? ' as a guest' : ' as '.$user->email }}
    </p>

    <ul class="apps">
        @foreach ($apps as $app)
            <li>
                <a href="{{ $app['url'] }}" class="card app">
                    <strong>{{ $app['name'] }}</strong>
                </a>
            </li>
        @endforeach
    </ul>

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
