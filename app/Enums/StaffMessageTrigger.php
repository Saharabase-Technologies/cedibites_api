<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * Which moment is allowed to put a message on somebody's screen.
 *
 * Separate from `visible_from`, and both can be set. The timestamp is a floor
 * the server enforces: nothing appears before it, ever. The trigger is the event
 * the client waits for once that floor has passed. "Monday at 6am, and then only
 * when they next sign in" is a sentence the two express together and neither
 * expresses alone.
 *
 * Every case here is decided in the browser, because every one of them is a fact
 * only the browser holds. The server cannot know whether this page load is newer
 * than the send, or whether a till just came back under somebody's hand.
 */
enum StaffMessageTrigger: string
{
    use HasEnumHelpers;

    /**
     * As soon as they are not mid-task.
     *
     * The default, and what every message sent before this feature existed did.
     * Still subject to the interruption gate, so it is "at the first fair
     * moment" rather than "this instant".
     */
    case Immediate = 'immediate';

    /**
     * Held until the app is loaded fresh.
     *
     * A page load, not strictly a sign-out and back in: the user chose this
     * reading deliberately, so a refresh or arriving at the portal counts. What
     * it will not do is appear inside a tab that was already open when the
     * message went out, which is the case that matters. A cashier mid-shift is
     * not interrupted by something sent while they were serving.
     */
    case NextSignIn = 'next_sign_in';

    /**
     * Held until the window becomes active again.
     *
     * For the till and the boards, which sit unattended for long stretches. It
     * waits for a real focus change, so if nobody ever leaves and returns to the
     * screen it will keep waiting. That is the literal promise and it is the
     * right one: the point is to appear when somebody walks back, not to appear
     * to an empty room.
     */
    case WindowActive = 'window_active';

    public function label(): string
    {
        return match ($this) {
            self::Immediate => 'Right away',
            self::NextSignIn => 'On next sign-in',
            self::WindowActive => 'When they return to the screen',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Immediate => 'Appears at the first moment they are not mid-task.',
            self::NextSignIn => 'Waits for the app to be opened again. Nobody is interrupted mid-shift.',
            self::WindowActive => 'Waits for somebody to come back to the screen. A screen nobody leaves never triggers it.',
        };
    }
}
