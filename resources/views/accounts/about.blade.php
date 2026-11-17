@extends('accounts.layout')

@section('title', 'About')
@section('width', 'landing')

@section('content')
    <article class="about-copy">
        <header>
            <h1>I'm Emanuel.</h1>
            <p class="about-intro">A full-stack developer in the UK. Online, I go by ChickenSplash.</p>
        </header>

        <p>I like to build things. Software is like Lego to me.</p>

        <h2>What I work on</h2>
        <p>At Premier Education Group, I maintain the BMS, the backend system that talks to all the apps and services that run the business.</p>

        <h2>A small server, plenty to build</h2>
        <p>My homelab runs on a small Linux machine at home. It hosts a real-time chat app, a music player, a journal and activity tracker, and a custom file-browsing interface. The Laravel-based HMS (Homelab Management System) provides the shared backend and accounts.</p>

        <p>Setting this up has given me hands-on experience with infrastructure, from running services in Docker to configuring DNS and connecting applications to the web through Cloudflare Tunnel.</p>

        <h2>Beyond the browser</h2>
        <p>I just enjoy building things. Rust and its ecosystem are usually my number one choice. With a capable AI tool to help, the sky (or my limited amount of computing power) is the limit.</p>

        <p>Since I love space, I decided to create a space simulator with realistic planetary system generation and simulated flight, grounded in maths and science.</p>

        <p>But building cool things is futile if I can't show them off, so I also like the idea of dabbling in content creation.</p>

        @include('accounts.contact')
    </article>
@endsection
