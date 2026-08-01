<?php

namespace App\Models;

use App\Enums\RecruitmentApplicationStatus;
use App\Enums\RecruitmentLinkKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RecruitmentLink extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'token',
        'kind',
        'branch_id',
        'created_by_user_id',
        'label',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => RecruitmentLinkKind::class,
            'expires_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('admin')
            ->logOnly(['kind', 'branch_id', 'label', 'expires_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * 48 random characters. Long enough that guessing is not a strategy, and
     * unrelated to anything sequential.
     */
    public static function generateToken(): string
    {
        return Str::random(48);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(RecruitmentApplication::class);
    }

    public function pendingApplications(): HasMany
    {
        return $this->applications()->where('status', RecruitmentApplicationStatus::Pending);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Whether this link will still accept a submission. */
    public function isOpen(): bool
    {
        return ! $this->isExpired();
    }

    /**
     * The branches to stamp on someone approved through this link.
     *
     * Empty for the call centre, and that emptiness is the point — it is what
     * User::isCompanyWide() reads. Never substitute a branch here to make a
     * form look tidier.
     *
     * @return array<int, int>
     */
    public function branchIdsForApproval(): array
    {
        return $this->branch_id ? [$this->branch_id] : [];
    }

    /** The posting as a person would say it: "Lakeside" or "Call Centre". */
    public function postingName(): string
    {
        return $this->branch?->name ?? $this->kind->label();
    }
}
