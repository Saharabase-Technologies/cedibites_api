<?php

namespace App\Services\Platform;

use App\Services\SystemSettingService;
use Illuminate\Support\Facades\Log;

/**
 * Settings a tech admin can change from the browser, without SSH.
 *
 * These are DB overrides on top of the values in `.env`, NOT edits to `.env`.
 * That distinction is the whole design, for three reasons:
 *
 *  1. A `.env` change does not take effect until `config:clear` runs, and the
 *     PM2 queue workers keep the old config until `queue:restart` runs after it.
 *     Half this system's work happens in those workers, so a `.env` toggle would
 *     appear to do nothing exactly where it matters most.
 *  2. A malformed write to `.env` stops the application booting. Recovery then
 *     needs SSH — the one thing this feature exists to avoid — so the failure
 *     mode destroys its own escape route.
 *  3. `.env` holds credentials. A screen that can write it is a screen that can
 *     be made to read it.
 *
 * An override read from the DB costs one cached lookup and is picked up by every
 * process on the next read.
 *
 * Each environment has its own database, so the prod panel governs prod and the
 * beta panel governs beta. There is deliberately no cross-environment control:
 * one browser tab able to reconfigure production from a beta login is a much
 * larger blast radius than the convenience is worth.
 */
class RuntimeSettings
{
    /** Prefix for the override rows, so they cannot collide with other settings. */
    private const PREFIX = 'runtime.';

    public function __construct(
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * Everything editable, with its current value and where that value came
     * from.
     *
     * NOTHING outside this list can be written. It is an allowlist rather than a
     * denylist on purpose: a denylist quietly grants access to every key added
     * later, and the key added later is exactly the one nobody thought about.
     *
     * Credentials never appear here. Hubtel keys, the app key, database
     * passwords and the Groq token are all deliberately absent and must stay so
     * — this is a behaviour panel, not a secrets manager.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            // ── Staff messaging ──────────────────────────────────────────────
            [
                'key' => 'staff_messaging.automation_enabled',
                'config' => 'staff_messaging.automation_enabled',
                'env' => 'STAFF_MESSAGING_AUTOMATION_ENABLED',
                'group' => 'Staff messaging',
                'label' => 'Send automatic cautions',
                'help' => 'The master switch. Off means rules still run and still record what they '
                    .'would have sent, but nobody is messaged. Each rule also has its own switch.',
                'type' => 'boolean',
                'danger' => true,
            ],
            [
                'key' => 'staff_messaging.recipient_hourly_cap',
                'config' => 'staff_messaging.recipient_hourly_cap',
                'env' => 'STAFF_MESSAGING_RECIPIENT_HOURLY_CAP',
                'group' => 'Staff messaging',
                'label' => 'Most automatic messages one person can get per hour',
                'help' => 'Across every rule, not per rule. Four rules each behaving reasonably on '
                    .'their own still add up to being shouted at. Set 0 to remove the cap.',
                'type' => 'integer',
                'min' => 0,
                'max' => 20,
            ],
            [
                'key' => 'staff_messaging.lookback_hours',
                'config' => 'staff_messaging.lookback_hours',
                'env' => 'STAFF_MESSAGING_LOOKBACK_HOURS',
                'group' => 'Staff messaging',
                'label' => 'How far back the rules look',
                'help' => 'Bounds every rule query. Raising this makes the five-minute scheduler run '
                    .'heavier; it does not make old orders newly eligible, because the cooldown '
                    .'still applies.',
                'type' => 'integer',
                'min' => 1,
                'max' => 168,
            ],
            [
                'key' => 'staff_messaging.default_sms_fallback_minutes',
                'config' => 'staff_messaging.default_sms_fallback_minutes',
                'env' => 'STAFF_MESSAGING_SMS_FALLBACK_MINUTES',
                'group' => 'Staff messaging',
                'label' => 'Text them if unread after (minutes)',
                'help' => 'Only the value the compose form starts on. Each message and each rule '
                    .'carries its own setting. This one costs money when it fires.',
                'type' => 'integer',
                'min' => 0,
                'max' => 1440,
            ],

            // ── Campaigns ────────────────────────────────────────────────────
            [
                'key' => 'campaigns.seed_mode',
                'config' => 'campaigns.seed_mode',
                'env' => 'CAMPAIGN_SEED_MODE',
                'group' => 'Campaigns',
                'label' => 'Campaign test mode',
                'help' => 'ON means every campaign goes to the staff test numbers only and no '
                    .'customer receives anything, whatever audience is chosen. Turning it OFF means '
                    .'the next send reaches real customers and is billed, for the whole audience: '
                    .'there is no recipient cap any more. Send yourself a test from the campaign '
                    .'screen before you turn this off.',
                'type' => 'boolean',
                'danger' => true,
            ],

            // ── Inventory ────────────────────────────────────────────────────
            [
                'key' => 'inventory.enabled',
                'config' => 'inventory.enabled',
                'env' => 'IMS_ENABLED',
                'group' => 'Inventory',
                'label' => 'Inventory system on',
                'help' => 'The IMS kill switch. Turning this off hides the inventory portal and '
                    .'stops stock moving with sales.',
                'type' => 'boolean',
                'danger' => true,
            ],

            // ── Orders ───────────────────────────────────────────────────────
            [
                'key' => 'orders.prep_default_minutes',
                'config' => 'orders.prep_default_minutes',
                'env' => 'ORDER_PREP_DEFAULT_MINUTES',
                'group' => 'Orders',
                'label' => 'Default prep time (minutes)',
                'help' => 'Used when there is not enough history at a branch to measure a real one.',
                'type' => 'integer',
                'min' => 1,
                'max' => 120,
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> keyed by setting key */
    public function definitionMap(): array
    {
        return collect($this->definitions())->keyBy('key')->all();
    }

    public function isEditable(string $key): bool
    {
        return isset($this->definitionMap()[$key]);
    }

    /**
     * The value in force: the override if one has been set, otherwise whatever
     * `.env` and config say.
     */
    public function get(string $key): mixed
    {
        $definition = $this->definitionMap()[$key] ?? null;

        if (! $definition) {
            return null;
        }

        $fallback = config($definition['config']);
        $stored = $this->settings->get(self::PREFIX.$key);

        if ($stored === null) {
            return $this->cast($fallback, $definition['type']);
        }

        return $this->cast($stored, $definition['type']);
    }

    /**
     * Every setting with its current value, its default, and which of the two is
     * winning.
     *
     * `source` is not decoration. "Why is this off when the server file says
     * true?" is the first question anybody asks, and without this field the
     * answer is invisible.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return collect($this->definitions())
            ->map(function (array $definition) {
                $stored = $this->settings->get(self::PREFIX.$definition['key']);
                $default = $this->cast(config($definition['config']), $definition['type']);

                return $definition + [
                    'value' => $stored === null ? $default : $this->cast($stored, $definition['type']),
                    'default' => $default,
                    'source' => $stored === null ? 'env' : 'override',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Override a setting.
     *
     * @throws \InvalidArgumentException when the key is not on the allowlist
     */
    public function set(string $key, mixed $value, ?int $byUserId = null): mixed
    {
        $definition = $this->definitionMap()[$key] ?? null;

        if (! $definition) {
            throw new \InvalidArgumentException("{$key} is not an editable setting.");
        }

        $cast = $this->cast($value, $definition['type']);

        if ($definition['type'] === 'integer') {
            // Clamped rather than rejected. Every number here has a range in
            // which it is merely unwise and outside which it is destructive —
            // a lookback of 100000 hours is a full table scan every five
            // minutes — and the bound is the honest place to stop it.
            $cast = max($definition['min'] ?? 0, min($definition['max'] ?? PHP_INT_MAX, (int) $cast));
        }

        $previous = $this->get($key);

        $this->settings->set(self::PREFIX.$key, $cast, $definition['type']);

        // Changing how the platform behaves is worth a permanent record, and the
        // question after an incident is always "who turned that on, and when".
        Log::channel('stack')->info('Runtime setting changed', [
            'key' => $key,
            'from' => $previous,
            'to' => $cast,
            'by_user_id' => $byUserId,
        ]);

        return $cast;
    }

    /**
     * Drop the override and go back to whatever the server file says.
     *
     * Worth having as its own action rather than asking somebody to retype the
     * default: the point of reverting is usually that you no longer trust your
     * own memory of what the default was.
     */
    public function revert(string $key, ?int $byUserId = null): mixed
    {
        $definition = $this->definitionMap()[$key] ?? null;

        if (! $definition) {
            throw new \InvalidArgumentException("{$key} is not an editable setting.");
        }

        $this->settings->forget(self::PREFIX.$key);

        Log::channel('stack')->info('Runtime setting reverted to env default', [
            'key' => $key,
            'by_user_id' => $byUserId,
        ]);

        return $this->cast(config($definition['config']), $definition['type']);
    }

    private function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            default => $value,
        };
    }
}
