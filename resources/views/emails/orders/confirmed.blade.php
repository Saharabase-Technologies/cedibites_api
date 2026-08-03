@extends('emails.layout')

@section('title', 'Order Confirmed - CediBites')

@section('content')
    <h2 class="greeting">Hello {{ $order->customer->user->name }}</h2>
    
    <p class="message">
        Great news! Your order has been confirmed and is being processed. We'll notify you when it's ready.
    </p>
    
    <div class="order-box">
        <h3>Order Details</h3>
        
        <div class="order-detail">
            <span class="order-label">Order Number:</span>
            <span class="order-value">{{ $order->order_number }}</span>
        </div>
        
        <div class="order-detail">
            <span class="order-label">Order Type:</span>
            <span class="order-value">{{ ucfirst($order->order_type) }}</span>
        </div>
        
        <div class="order-detail">
            <span class="order-label">Total Amount:</span>
            <span class="order-value">GHS {{ number_format($order->total_amount, 2) }}</span>
        </div>
        
        {{-- Hidden rather than rendered blank. This row printed " minutes" on
             every confirmation email until estimated_prep_time was actually
             populated at order creation. --}}
        @if($order->estimated_prep_time)
        <div class="order-detail">
            <span class="order-label">Estimated Time:</span>
            <span class="order-value">{{ $order->estimated_prep_time }} minutes</span>
        </div>
        @endif

        @if($order->order_type === 'delivery')
        <div class="order-detail">
            <span class="order-label">Delivery Address:</span>
            <span class="order-value">{{ $order->delivery_address }}</span>
        </div>
        @endif
    </div>
    
    <div style="text-align: center;">
        <a href="{{ config('app.frontend_url') }}/orders/{{ $order->id }}" class="button">
            View Order Details
        </a>
    </div>
    
    <p class="message" style="margin-top: 15px; font-size: 13px; color: #8b7f70;">
        Need help? Contact us at <a href="tel:+233XXXXXXXXX" style="color: #e49925;">+233 XX XXX XXXX</a> or reply to this email.
    </p>
@endsection
