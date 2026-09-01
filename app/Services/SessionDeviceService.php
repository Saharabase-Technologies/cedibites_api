<?php

namespace App\Services;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * What machine a session is on.
 *
 * Sanctum records who a token belongs to and when it was last used, and nothing
 * about where it is. On a floor where one cashier is signed in on a till, a
 * tablet and their own phone, "sign this session out" is an unanswerable
 * question without it.
 */
class SessionDeviceService
{
    /**
     * Record the device a freshly minted token was issued to.
     *
     * Called right after `createToken`. A failure here must never take a login
     * down with it — this is a label on a session, not part of authenticating
     * one — so it is deliberately forgiving of a missing or absurd user agent.
     */
    public function stamp(PersonalAccessToken $token, Request $request): void
    {
        $token->forceFill([
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000) ?: null,
            'ip_address' => $request->ip(),
        ])->save();
    }

    /**
     * Sort a user agent into something a person can act on.
     *
     * Deliberately coarse. The reader is choosing which of somebody's screens to
     * end, not auditing browser versions, and a label that says "iPhone" when
     * the truth is an iPad is worse than one that says "mobile". Order matters:
     * every Android tablet also says "Android", and an iPad in desktop mode
     * says "Macintosh", so the tablet tests have to run before the others.
     */
    public function classify(?string $userAgent): string
    {
        if (blank($userAgent)) {
            return 'unknown';
        }

        $ua = mb_strtolower($userAgent);

        // iPadOS 13+ reports itself as a Mac. The touch-points hint is the only
        // thing in the string that gives it away, and it is not always there —
        // an iPad in desktop mode that omits it lands on "desktop", which is
        // what the person is being shown anyway.
        $isTablet = str_contains($ua, 'ipad')
            || (str_contains($ua, 'android') && ! str_contains($ua, 'mobile'))
            || str_contains($ua, 'tablet')
            || str_contains($ua, 'kindle')
            || str_contains($ua, 'silk')
            || str_contains($ua, 'playbook');

        if ($isTablet) {
            return 'tablet';
        }

        $isMobile = str_contains($ua, 'iphone')
            || str_contains($ua, 'ipod')
            || (str_contains($ua, 'android') && str_contains($ua, 'mobile'))
            || str_contains($ua, 'windows phone')
            || str_contains($ua, 'blackberry')
            || str_contains($ua, 'opera mini')
            || str_contains($ua, 'mobile safari');

        if ($isMobile) {
            return 'mobile';
        }

        $isDesktop = str_contains($ua, 'windows nt')
            || str_contains($ua, 'macintosh')
            || str_contains($ua, 'x11')
            || str_contains($ua, 'linux')
            || str_contains($ua, 'cros');

        return $isDesktop ? 'desktop' : 'unknown';
    }

    /**
     * The browser or app, for the line under the device.
     *
     * Only enough to tell two of somebody's screens apart. Order matters here
     * too: Edge and Opera both carry "chrome" in their strings, and Chrome
     * carries "safari".
     */
    public function browser(?string $userAgent): ?string
    {
        if (blank($userAgent)) {
            return null;
        }

        $ua = mb_strtolower($userAgent);

        return match (true) {
            str_contains($ua, 'edg/') || str_contains($ua, 'edga/') => 'Edge',
            str_contains($ua, 'opr/') || str_contains($ua, 'opera') => 'Opera',
            str_contains($ua, 'samsungbrowser') => 'Samsung Internet',
            str_contains($ua, 'firefox') => 'Firefox',
            str_contains($ua, 'chrome') || str_contains($ua, 'crios') => 'Chrome',
            str_contains($ua, 'safari') => 'Safari',
            default => null,
        };
    }
}
