<?php

namespace App\Services\StaffMessaging;

use App\Models\Branch;
use App\Models\User;

/**
 * Fills the merge fields in a rule's template.
 *
 * First names only. "Kwame, order #1042 has not moved in 47 minutes" is a
 * colleague speaking; "Kwame Mensah, order #1042…" is a disciplinary letter, and
 * the difference decides whether people read these or dread them.
 *
 * An unrecognised field renders as NOTHING, never as literal braces. A message
 * arriving on somebody's phone reading "check with {branch}" tells them the
 * system is broken and, worse, that nobody read it before it went out.
 */
class StaffMessageRenderer
{
    /**
     * @param  array<string, scalar|null>  $mergeData
     */
    public function render(string $template, User $recipient, ?int $branchId = null, array $mergeData = []): string
    {
        $values = array_merge([
            'name' => $recipient->name,
            'first_name' => $this->firstName($recipient->name),
            'branch' => $this->branchName($branchId),
        ], $mergeData);

        return trim((string) preg_replace_callback(
            '/\{([a-z_]+)\}/i',
            function (array $matches) use ($values) {
                $key = strtolower($matches[1]);

                // Empty string, not the raw token. See the class docblock.
                return isset($values[$key]) ? (string) $values[$key] : '';
            },
            $template,
        ));
    }

    private function firstName(?string $name): string
    {
        $first = trim(explode(' ', trim((string) $name))[0] ?? '');

        // "there" so a nameless account still produces "Hi there" rather than
        // "Hi ," — a stray comma reads as a bug in a message about their conduct.
        return $first !== '' ? $first : 'there';
    }

    private function branchName(?int $branchId): string
    {
        if ($branchId === null) {
            return '';
        }

        return Branch::query()->whereKey($branchId)->value('name') ?? '';
    }
}
