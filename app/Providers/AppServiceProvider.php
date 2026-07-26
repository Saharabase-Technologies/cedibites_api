<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Feedback voice-note transcription — provider chosen by config; falls
        // back to a no-op when unconfigured.
        $this->app->bind(
            \App\Services\Feedback\Transcription\Transcriber::class,
            function () {
                $provider = config('feedback.transcription.provider');

                if ($provider === 'groq' && config('feedback.transcription.groq_key')) {
                    return new \App\Services\Feedback\Transcription\GroqTranscriber(
                        config('feedback.transcription.groq_key'),
                        config('feedback.transcription.groq_model', 'whisper-large-v3'),
                    );
                }

                if ($provider === 'openai' && config('feedback.transcription.openai_key')) {
                    return new \App\Services\Feedback\Transcription\OpenAiTranscriber(
                        config('feedback.transcription.openai_key'),
                        config('feedback.transcription.openai_model', 'whisper-1'),
                    );
                }

                return new \App\Services\Feedback\Transcription\NullTranscriber;
            },
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register the Brevo (Sendinblue) mail transport using the HTTP API key.
        Mail::extend('brevo', function () {
            return (new BrevoTransportFactory)->create(
                new Dsn('brevo+api', 'default', config('services.brevo.key'))
            );
        });

        // Register custom SMS notification channel
        \Illuminate\Support\Facades\Notification::resolved(function ($service) {
            $service->extend('sms', function ($app) {
                return new \App\Channels\SmsChannel($app->make(\App\Services\HubtelSmsService::class));
            });
        });

        // Register Order observer
        \App\Models\Order::observe(\App\Observers\OrderObserver::class);

        // Register broadcasting auth under the v1 prefix with Sanctum middleware
        Broadcast::routes(['prefix' => 'v1', 'middleware' => ['auth:sanctum']]);

        // Register Payment observer
        \App\Models\Payment::observe(\App\Observers\PaymentObserver::class);

        // Settling a wastage claim kills any live phone-upload QR code on it.
        \App\Models\Inventory\Wastage::observe(\App\Observers\WastageObserver::class);

        // Register rate limiters (relaxed in local for testing)
        \Illuminate\Support\Facades\RateLimiter::for('otp-send', function ($request) {
            $limit = app()->environment('local') ? 60 : 3;

            return \Illuminate\Cache\RateLimiting\Limit::perHour($limit)->by($request->input('phone') ?? $request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('otp-verify', function ($request) {
            $limit = app()->environment('local') ? 30 : 5;

            return \Illuminate\Cache\RateLimiting\Limit::perMinute($limit)->by($request->input('phone') ?? $request->ip());
        });

        /*
         * Phone-as-camera upload sessions. These two routes sit outside auth -
         * the token in the URL is the whole credential - so they are limited on
         * both axes at once:
         *
         *   by token — caps what one leaked QR code can do.
         *   by IP    — caps guessing. A per-token limit is useless against
         *              brute force, because every guess is a different token.
         *
         * The IP allowance is the looser of the two on purpose: a branch is one
         * NAT'd connection, and several people photographing several crates
         * must not lock each other out.
         */
        \Illuminate\Support\Facades\RateLimiter::for('upload-session-view', function ($request) {
            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('us-view:'.$request->route('token')),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by('us-view-ip:'.$request->ip()),
            ];
        });

        \Illuminate\Support\Facades\RateLimiter::for('upload-session-store', function ($request) {
            return [
                // `max_files` is the real cap on a session; this is the cap on
                // how fast it can be spent, including on rejected files.
                \Illuminate\Cache\RateLimiting\Limit::perMinute(20)->by('us-store:'.$request->route('token')),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by('us-store-ip:'.$request->ip()),
            ];
        });
    }
}
