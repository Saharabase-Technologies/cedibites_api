<?php

namespace App\Domain\Staff;

use Illuminate\Support\Facades\Hash;

/**
 * How a new staff account's password is decided.
 *
 * There are four ways in and they differ in more than the string: whether the
 * password goes into the reversible vault, whether the holder is forced to
 * change it, and whether it is shown back to the admin who did the hiring.
 * Getting one of those wrong is silent, so they are stated here once rather
 * than re-derived at each call site.
 */
final readonly class PasswordPlan
{
    private function __construct(
        /** Ready to assign to User::password. */
        public string $hash,

        /** Kept in users.recoverable_password, or null to store nothing. */
        public ?string $recoverable,

        /** Whether the holder must change it at first login. */
        public bool $mustReset,

        /**
         * The password in the clear, when this side of the system ever knew it.
         * Null only when the holder chose it and we hold nothing but the hash.
         * This is what the welcome notification quotes back to them.
         */
        public ?string $plain,

        /**
         * Handed back to the admin who did the hiring, so they can pass it on.
         * Null when the admin already knows it (they typed it) and null when it
         * is not theirs to read (the holder chose it).
         */
        public ?string $disclosable,
    ) {}

    /**
     * The staff editor's three modes, unchanged from EmployeeController::store.
     *
     * - `custom`  — the admin typed it. Vaulted, no forced reset, not echoed
     *               back (they already know it).
     * - `prompt`  — a throwaway they change at first login. Not vaulted, because
     *               it is about to stop being true.
     * - `auto`    — generated for the admin to pass on. Vaulted and echoed back.
     */
    public static function forMode(string $mode, ?string $password = null): self
    {
        if ($mode === 'prompt') {
            $generated = self::generate();

            return new self(Hash::make($generated), null, true, $generated, $generated);
        }

        if ($mode === 'custom' && filled($password)) {
            return new self(Hash::make($password), $password, false, $password, null);
        }

        $generated = self::generate();

        return new self(Hash::make($generated), $generated, false, $generated, $generated);
    }

    /**
     * The holder already chose it — the recruitment form takes a password at
     * submit and we keep only the hash.
     *
     * Nothing is vaulted and nothing is disclosed. The reversible vault exists
     * so an admin can pass on a password they generated; a password the person
     * picked themselves is not the admin's to read. No forced reset either —
     * they chose it, so there is nothing to correct.
     */
    public static function alreadyChosen(string $hash): self
    {
        return new self($hash, null, false, null, null);
    }

    /** Pronounceable enough to read down a phone line, which is how it travels. */
    private static function generate(): string
    {
        $adjectives = ['Happy', 'Bright', 'Quick', 'Lucky', 'Cool', 'Bold', 'Sweet', 'Grand', 'Smart', 'Calm', 'Warm', 'Fresh', 'Kind', 'Safe', 'Gold'];
        $nouns = ['Star', 'Blue', 'Wave', 'Moon', 'Tree', 'Lake', 'Fire', 'Rock', 'Bird', 'Lion', 'Bear', 'Rain', 'Peak', 'Sand', 'Box'];
        $specials = ['!', '@', '#', '$'];

        return $adjectives[array_rand($adjectives)]
            .$nouns[array_rand($nouns)]
            .random_int(10, 999)
            .$specials[array_rand($specials)];
    }
}
