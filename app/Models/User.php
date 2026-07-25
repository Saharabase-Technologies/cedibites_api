<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Permission;
use App\Models\Inventory\Location;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\CausesActivity;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use CausesActivity, HasApiTokens, HasFactory, HasPushSubscriptions, HasRoles, LogsActivity, Notifiable, SoftDeletes;

    /** Memoised inventory location scope — see accessibleLocationIds(). */
    private bool $locationScopeResolved = false;

    /** @var array<int, int>|null */
    private ?array $locationScopeCache = null;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('auth')
            ->logOnly([
                'name',
                'email',
                'phone',
                'must_reset_password',
                'password_reset_required_at',
                'platform_passcode',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * The default guard name for Spatie Permission.
     *
     * @var string
     */
    protected $guard_name = 'api';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'recoverable_password',
        'must_reset_password',
        'password_reset_required_at',
        'platform_passcode',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'recoverable_password',
        'remember_token',
        'platform_passcode',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'recoverable_password' => 'encrypted',
            'platform_passcode' => 'hashed',
            'must_reset_password' => 'boolean',
            'password_reset_required_at' => 'datetime',
        ];
    }

    /**
     * Normalise a Ghana phone number to canonical "+233XXXXXXXXX" form,
     * regardless of how it was entered (+233…, 233…, 0…, with spaces/dashes).
     * The single source of truth for phone matching & storage.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        $national = str_starts_with($digits, '233') ? $digits : '233'.ltrim($digits, '0');

        return '+'.$national;
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * Inventory locations this user is allowed to read.
     *
     * `null` means unrestricted — the caller must skip scoping entirely rather
     * than treating it as "no locations". Users holding
     * `inventory.view_all_locations` (admin, warehouse manager, purchasing
     * clerk) see everything; everyone else is confined to the locations
     * belonging to their assigned branches, which for a branch manager is
     * normally the single branch they run.
     *
     * An empty array is meaningful and distinct from `null`: a user with no
     * branch assignment sees nothing.
     *
     * @return array<int, int>|null
     */
    public function accessibleLocationIds(): ?array
    {
        // `null` is a meaningful result, so resolution needs its own flag.
        if ($this->locationScopeResolved) {
            return $this->locationScopeCache;
        }

        $this->locationScopeCache = $this->resolveAccessibleLocationIds();
        $this->locationScopeResolved = true;

        return $this->locationScopeCache;
    }

    /**
     * @return array<int, int>|null
     */
    private function resolveAccessibleLocationIds(): ?array
    {
        if ($this->can(Permission::InventoryViewAllLocations->value)) {
            return null;
        }

        $branchIds = $this->employee?->branches()->pluck('branches.id') ?? collect();

        if ($branchIds->isEmpty()) {
            return [];
        }

        return Location::query()
            ->whereIn('branch_id', $branchIds)
            ->pluck('id')
            ->all();
    }

    /**
     * The satellite location this user implicitly acts for.
     *
     * A branch manager runs one branch, so screens that would otherwise ask
     * "which branch is this for?" can answer it themselves. Returns null when
     * the answer is genuinely ambiguous — an unrestricted user (warehouse
     * manager, admin) who could mean any branch, a user spanning several
     * branches, or one whose branch has no inventory location yet. Callers must
     * fall back to asking, or fail with a message, rather than guessing.
     */
    public function defaultInventoryLocationId(): ?int
    {
        $ids = $this->accessibleLocationIds();

        if ($ids === null || $ids === []) {
            return null;
        }

        $satellites = Location::query()
            ->whereIn('id', $ids)
            ->where('type', 'satellite')
            ->pluck('id');

        return $satellites->count() === 1 ? (int) $satellites->first() : null;
    }
}
