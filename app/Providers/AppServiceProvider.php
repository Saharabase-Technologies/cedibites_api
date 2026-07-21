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
                $key = config('feedback.transcription.openai_key');

                if (config('feedback.transcription.provider') === 'openai' && $key) {
                    return new \App\Services\Feedback\Transcription\OpenAiTranscriber(
                        $key,
                        config('feedback.transcription.openai_model', 'whisper-1'),
                    );
                }

                return new \App\Services\Feedback\Transcription\NullTranscriber();
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

        // Register rate limiters (relaxed in local for testing)
        \Illuminate\Support\Facades\RateLimiter::for('otp-send', function ($request) {
            $limit = app()->environment('local') ? 60 : 3;

            return \Illuminate\Cache\RateLimiting\Limit::perHour($limit)->by($request->input('phone') ?? $request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('otp-verify', function ($request) {
            $limit = app()->environment('local') ? 30 : 5;

            return \Illuminate\Cache\RateLimiting\Limit::perMinute($limit)->by($request->input('phone') ?? $request->ip());
        });
    }
}
