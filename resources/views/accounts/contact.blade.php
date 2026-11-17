<section id="contact" class="contact-section" aria-labelledby="contact-heading">
    <h2 id="contact-heading">Contact</h2>

    <!--email_off-->
    <div class="contact-methods">
        @foreach ([
            ['label' => 'email address', 'value' => 'guardeded@gmail.com', 'href' => 'mailto:guardeded@gmail.com'],
            ['label' => 'phone number', 'value' => '+44 7858 501493', 'href' => 'tel:+447858501493'],
        ] as $method)
            <div class="contact-method">
                <a href="{{ $method['href'] }}">{{ $method['value'] }}</a>
                <button type="button" class="contact-copy" data-copy="{{ $method['value'] }}" aria-label="Copy {{ $method['label'] }}" title="Copy {{ $method['label'] }}">
                    <svg class="copy-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/></svg>
                    <svg class="copied-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                </button>
            </div>
        @endforeach
    </div>
    <!--/email_off-->
    <p class="contact-copy-status small muted" role="status"></p>

    <form class="contact-form" method="post" action="{{ route('accounts.contact') }}">
        @csrf
        <div class="contact-fields">
            <div class="field">
                <label for="contact-email">Email</label>
                <input id="contact-email" type="email" name="email" autocomplete="email" required maxlength="255" value="{{ old('email') }}" @error('email') aria-invalid="true" @enderror>
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="contact-subject">Subject</label>
                <input id="contact-subject" type="text" name="subject" required maxlength="150" value="{{ old('subject') }}" @error('subject') aria-invalid="true" @enderror>
                @error('subject') <p class="error">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="field">
            <label for="contact-message">Message</label>
            <textarea id="contact-message" name="message" rows="5" required maxlength="5000" @error('message') aria-invalid="true" @enderror>{{ old('message') }}</textarea>
            @error('message') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div class="contact-honeypot" aria-hidden="true" inert>
            <label for="contact-website">Website</label>
            <input id="contact-website" type="text" name="website" tabindex="-1" autocomplete="off">
        </div>
        <div class="contact-submit">
            <button type="submit">Send message</button>
            <p class="contact-form-status small muted" role="status">@if (session('contact_sent'))Thanks, your message has been received.@endif @error('form'){{ $message }}@enderror</p>
        </div>
    </form>
</section>
