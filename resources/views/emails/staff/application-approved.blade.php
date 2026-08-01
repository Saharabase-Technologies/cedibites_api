@extends('emails.layout')

@section('title', 'Welcome to CediBites')

@php($portalLabel = $portalLabel ?? 'Staff Portal')

@section('content')
    <h2 class="greeting">Hello {{ $user->name }}</h2>

    <p class="message">
        Welcome to the team. You&rsquo;ve been added as <strong>{{ $position }}</strong>, and your
        account is ready to use.
    </p>

    <div class="order-box">
        <h3>Signing in</h3>
        <p style="margin: 0; color: #fbf6ed; font-family: 'Cabin', sans-serif; font-size: 15px;">
            Use your phone number and <strong>the password you chose on the form</strong>.
        </p>
        <p style="margin: 12px 0 0 0; font-size: 13px; color: #8b7f70; font-family: 'Cabin', sans-serif;">
            We never stored that password in a form anyone can read, so nobody here can tell you what it
            was. If you&rsquo;ve forgotten it, use &ldquo;Forgot password&rdquo; on the login page.
        </p>
    </div>

    <div style="text-align: center;">
        <a href="{{ config('app.frontend_url', '') }}/staff/login" class="button">
            Log in to {{ $portalLabel }}
        </a>
    </div>

    <p class="message" style="margin-top: 15px; font-size: 13px; color: #8b7f70;">
        Weren&rsquo;t expecting this? If you believe this was sent in error, contact us at
        <a href="mailto:support@cedibites.com" style="color: #e49925; text-decoration: none;">support@cedibites.com</a>.
    </p>
@endsection
