@extends('accounts.form')

@section('fields')
    @include('accounts.field', ['name' => 'display_name', 'label' => 'Display name', 'maxlength' => 32, 'autofocus' => true])
    @include('accounts.terms-box')
    <button type="submit">Join</button>
@endsection
