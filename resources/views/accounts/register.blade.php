@extends('accounts.form')

@section('fields')
    @include('accounts.field', ['name' => 'display_name', 'label' => 'Display name', 'maxlength' => 32, 'autofocus' => true])
    @include('accounts.field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'autocomplete' => 'email'])
    @include('accounts.field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'autocomplete' => 'new-password', 'minlength' => 8])
    @include('accounts.field', ['name' => 'password_confirmation', 'label' => 'Confirm password', 'type' => 'password', 'autocomplete' => 'new-password'])
    @include('accounts.terms-box')
    <button type="submit">Create account</button>
@endsection
