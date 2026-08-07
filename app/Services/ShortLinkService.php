<?php

namespace App\Services;

use App\Models\LinkClick;
use App\Models\ShortLink;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Making short links, resolving them, and counting the taps.
 */
class ShortLinkService
{
    /**
     * How many times to re-roll a token before giving up.
     *
     * Six base62 characters is 5.7 x 10^10 combinations, so a collision needs
     * roughly a quarter of a million existing links before it is even likely
     * once. The retry is here for the day somebody shortens the token length in
     * config, not for today.
     */
    private const TOKEN_ATTEMPTS = 5;

    public function create(
        string $label,
        string $targetUrl,
        int $createdByUserId,
        ?CarbonInterface $expiresAt = null,
    ): ShortLink {
        for ($attempt = 1; $attempt <= self::TOKEN_ATTEMPTS; $attempt++) {
            try {
                return ShortLink::create([
                    'token' => ShortLink::generateToken(),
                    'label' => $label,
                    'target_url' => $targetUrl,
                    'created_by_user_id' => $createdByUserId,
                    'expires_at' => $expiresAt,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // The unique index on `token` is the collision check. Asking
                // first would be a race; letting the database refuse is not.
                if ($attempt === self::TOKEN_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        // Unreachable: the loop either returns or rethrows on its last pass.
        throw new \RuntimeException('Could not allocate a short link token.');
    }

    /**
     * The link behind a token, if there is one and it is still live.
     *
     * Expired and never-existed answer the same way. Telling them apart would
     * turn this into an oracle for testing whether a token is real.
     */
    public function resolve(string $token): ?ShortLink
    {
        $link = ShortLink::where('token', $token)->first();

        return $link?->isActive() ? $link : null;
    }

    /**
     * Count the tap.
     *
     * Never allowed to be the reason a customer does not reach the menu: a
     * failure here is logged and swallowed, and the caller redirects anyway.
     * Losing one datapoint out of 28,000 is survivable; a 500 where a redirect
     * should have been is not.
     *
     * The increment is an atomic UPDATE rather than a read-modify-write, because
     * a blast produces simultaneous clicks and read-modify-write loses some of
     * them.
     */
    public function recordClick(ShortLink $link, ?string $userAgent = null, ?string $referer = null): void
    {
        try {
            DB::transaction(function () use ($link, $userAgent, $referer) {
                LinkClick::create([
                    'short_link_id' => $link->id,
                    'clicked_at' => now(),
                    'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
                    'referer' => $referer ? mb_substr($referer, 0, 500) : null,
                ]);

                ShortLink::whereKey($link->id)->increment('click_count');
            });
        } catch (\Throwable $e) {
            Log::warning('Failed to record a short link click', [
                'short_link_id' => $link->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Trim the click timeline past the retention window.
     *
     * `short_links.click_count` is untouched — that total is what campaign
     * reporting reads, and a report must not return a different number this
     * month than it did last month.
     */
    public function pruneClicks(int $days): int
    {
        return LinkClick::where('clicked_at', '<', now()->subDays($days))->delete();
    }
}
