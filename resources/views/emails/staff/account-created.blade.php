@extends('emails.layout')

@section('title', (! empty($roleLabel ?? null)) ? 'Welcome to CediBites' : 'Your Staff Account Has Been Created - CediBites')

@section('content')
    <h2 class="greeting">Hello {{ $user->name }}</h2>

    <p class="message">
        @if (! empty($roleLabel ?? null))
            You&rsquo;ve been added as a <strong>{{ $roleLabel }}</strong> on CediBites. You can now log in to the portal using your email or phone number and the temporary password below.
        @else
            Your CediBites staff account has been created. You can now log in to the staff portal using your email or phone number and the temporary password below.
        @endif
    </p>

    <div class="order-box">
        <h3>Your temporary password</h3>
        <p style="margin: 0; color: #fbf6ed; font-family: 'Cabin', sans-serif; font-size: 16px; font-weight: 600; letter-spacing: 0.05em;">
            {{ $temporaryPassword }}
        </p>
        <p style="margin: 12px 0 0 0; font-size: 13px; color: #8b7f70; font-family: 'Cabin', sans-serif;">
            For security, we recommend changing this password after your first login.
        </p>
    </div>

    <div style="text-align: center;">
        <a href="{{ config('app.frontend_url', '') }}/staff/login" class="button">
            Log in to Staff Portal
        </a>
    </div>

    <p class="message" style="margin-top: 15px; font-size: 13px; color: #8b7f70;">
        If you did not expect this account, please contact your administrator or reply to this email.
    </p>
@endsection
