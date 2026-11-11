@extends('accounts.form')

@section('fields')
    <p class="muted small">No email or password. A guest account lasts until you log out, then it's deleted with everything in it.</p>
    @include('accounts.field', ['name' => 'display_name', 'label' => 'Display name', 'maxlength' => 32, 'autofocus' => true])
    @include('accounts.terms-box')
    <button type="submit">Join</button>
@endsection
