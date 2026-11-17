@extends('accounts.layout')

@section('title', 'Portfolio')
@section('width', 'landing')

@section('content')
    @php
        $sections = [
            'Hosted on my homelab' => [
                ['name' => 'Chat', 'slug' => 'chat', 'url' => 'https://chat.chickensplash.dpdns.org'],
                ['name' => 'Music', 'slug' => 'music', 'url' => 'https://music.chickensplash.dpdns.org'],
                ['name' => 'Daily', 'slug' => 'daily', 'url' => 'https://daily.chickensplash.dpdns.org'],
            ],
            'GitHub projects' => [
                ['name' => 'Night Sky', 'slug' => 'night-sky', 'url' => 'https://github.com/ChickenSplash/night-sky'],
            ],
        ];
    @endphp

    @foreach ($sections as $heading => $projects)
        <section class="portfolio-section" aria-labelledby="portfolio-heading-{{ $loop->index }}">
            <h2 id="portfolio-heading-{{ $loop->index }}">{{ $heading }}</h2>
            <div class="portfolio-layout">
                @foreach ($projects as $project)
                    @php($image = 'images/portfolio/'.$project['slug'].'.webp')
                    <a class="project-card" href="{{ $project['url'] }}">
                        @if (is_file(public_path($image)))
                            <img src="/{{ $image }}?v={{ filemtime(public_path($image)) }}" alt="" width="800" loading="lazy">
                        @endif
                        <span>{{ $project['name'] }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach

@endsection
