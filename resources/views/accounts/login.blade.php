@extends('accounts.form')

@section('fields')
    @include('accounts.field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'autocomplete' => 'email', 'autofocus' => true])
    @include('accounts.field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'autocomplete' => 'current-password'])
    <label class="check">
        <input type="checkbox" name="remember" value="1" @checked(old('remember', true))>
        <span>Remember me</span>
    </label>
    <button type="submit">Log in</button>
@endsection
