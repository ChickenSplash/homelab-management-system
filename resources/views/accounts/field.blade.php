@php($id = 'field-'.$name)
<div class="field">
    <label for="{{ $id }}">{{ $label }}</label>
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type ?? 'text' }}"
        @if (($type ?? 'text') !== 'password') value="{{ old($name) }}" @endif
        @isset($autocomplete) autocomplete="{{ $autocomplete }}" @endisset
        @isset($maxlength) maxlength="{{ $maxlength }}" @endisset
        @isset($minlength) minlength="{{ $minlength }}" @endisset
        @if ($autofocus ?? false) autofocus @endif
        @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
        required
    >
    @error($name)
        <p class="error" id="{{ $id }}-error">{{ $message }}</p>
    @enderror
</div>
