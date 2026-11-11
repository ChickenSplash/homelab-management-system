{{-- Required to join or register. Opens in a new tab so the form keeps what's typed. --}}
<div class="field">
    <label class="check">
        <input type="checkbox" name="terms" value="1" required @checked(old('terms')) @error('terms') aria-invalid="true" @enderror>
        <span>I'm 18 or over and agree to the <a href="/terms" target="_blank" rel="noopener">terms and privacy policy</a></span>
    </label>
    @error('terms')
        <p class="error">{{ $message }}</p>
    @enderror
</div>
