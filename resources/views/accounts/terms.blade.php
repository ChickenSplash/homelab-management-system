{{-- One set of terms for every app, readable signed in or out. --}}
@extends('accounts.layout')

@php($contact = 'guardeded@gmail.com')

@section('title', 'Terms and privacy')
@section('width', 'wide')

@section('content')
    <p><a href="/">← Back</a></p>

    <h1>Terms and privacy</h1>
    <p class="muted small">Last updated 11 November 2026</p>

    <section>
        <h2>About</h2>
        <p>
            Chat and Music are small personal hobby projects hosted in the United Kingdom. They share one
            account system, so these terms cover all of them. They're free and provided as is. By creating an
            account or joining as a guest, you agree to these terms.
        </p>
        <p>Contact, reports and complaints: <a href="mailto:{{ $contact }}">{{ $contact }}</a></p>
    </section>

    <section>
        <h2>Who can use it</h2>
        <p>You must be <strong>18 or over</strong>.</p>
    </section>

    <section>
        <h2>What's not allowed</h2>
        <p><strong>No sexual or pornographic content of any kind</strong>, including links to it.</p>
        <p>Don't post, send, publish or link to anything illegal in the UK, including:</p>
        <ul>
            <li>sexual content involving children, or anything that grooms or exploits a child</li>
            <li>threats, harassment, stalking or abuse</li>
            <li>hatred against people for their race, religion, sexuality, disability or gender identity</li>
            <li>terrorist content, or encouraging violence</li>
            <li>encouraging or assisting self-harm or suicide</li>
            <li>sharing or threatening to share intimate images of someone without their consent</li>
            <li>fraud, scams, or selling drugs, weapons or other illegal items</li>
        </ul>
        <p>This includes music titles, lyrics and cover art. Spam and flooding aren't allowed either.</p>
    </section>

    <section>
        <h2>Music</h2>
        <ul>
            <li>Tracks marked AI were made with AI tools. Each track is published by the creator named on it.</li>
            <li>
                Creators must own, or have the rights to share, everything they publish. Music that infringes
                someone else's copyright is removed once we're aware of it: email
                <a href="mailto:{{ $contact }}">{{ $contact }}</a> with the track and your claim.
            </li>
            <li>The music is for streaming here. Don't copy it elsewhere unless its creator allows that.</li>
        </ul>
    </section>

    <section>
        <h2>How you're protected</h2>
        <ul>
            <li><strong>Blocking:</strong> in Chat you can block anyone. Neither of you can message the other.</li>
            <li><strong>Groups:</strong> owners, admins and mods can delete messages, and kick or ban members.</li>
            <li><strong>Reporting:</strong> email <a href="mailto:{{ $contact }}">{{ $contact }}</a> with the person, group or track and what's wrong.</li>
            <li>
                <strong>Removal:</strong> illegal content is removed once we're aware of it. Accounts that break
                these rules may be deleted without warning. Where the law requires it, content is reported to the
                police or the National Crime Agency.
            </li>
        </ul>
    </section>

    <section>
        <h2>Complaints and appeals</h2>
        <p>
            Email <a href="mailto:{{ $contact }}">{{ $contact }}</a> if you disagree with a removal or ban, think a
            report wasn't handled properly, or have any other concern about the service.
        </p>
    </section>

    <section>
        <h2>Your data</h2>
        <ul>
            <li>
                <strong>Registered accounts:</strong> display name, email address and a hashed password. The
                password itself is never stored.
            </li>
            <li><strong>Guests:</strong> display name only.</li>
            <li>
                <strong>Chat:</strong> messages, friends, blocks, group memberships and online status.
            </li>
            <li>
                <strong>Music:</strong> your playlists, liked songs and your last 200 plays (for "Recently
                played"). Playlists are private to you.
            </li>
            <li>
                <strong>Where:</strong> a private server in the UK. Traffic passes through Cloudflare, which sees
                your IP address.
            </li>
            <li>
                <strong>Cookies:</strong> only the ones needed to keep you signed in across the apps, plus Music's
                volume setting stored in your browser. No tracking or advertising.
            </li>
            <li>Your data is never sold or shared, except where the law requires it.</li>
        </ul>
    </section>

    <section>
        <h2>When data is deleted</h2>
        <ul>
            <li>A deleted message has its text erased. Only a "message deleted" placeholder is kept.</li>
            <li>
                Guest accounts are deleted when you log out (from any app) or your session expires, with their
                playlists and likes.
            </li>
            <li>To delete a registered account, email us from the address you signed up with.</li>
            <li>
                When an account is deleted, its profile goes. Messages in conversations other people are still in
                stay, shown as from "Deleted user". Ask if you want those removed too.
            </li>
            <li>Database backups may hold deleted data until they're replaced.</li>
        </ul>
        <p>
            You can ask for a copy of your data, a correction, or for it to be erased, at
            <a href="mailto:{{ $contact }}">{{ $contact }}</a>. If you're unhappy with how your data is handled,
            you can complain to the <a href="https://ico.org.uk">Information Commissioner's Office</a>.
        </p>
    </section>

    <section>
        <h2>No guarantees</h2>
        <p>
            The service may change, go offline or close at any time, and data may be lost. Don't rely on it for
            anything important.
        </p>
    </section>
@endsection
