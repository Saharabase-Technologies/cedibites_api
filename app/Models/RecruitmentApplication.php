<?php

namespace App\Models;

use App\Enums\RecruitmentApplicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RecruitmentApplication extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'recruitment_link_id',
        'name',
        'phone',
        'email',
        'password_hash',
        'ghana_card_id',
        'date_of_birth',
        'nationality',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'created_user_id',
    ];

    /**
     * The password hash never leaves the model. It is handed to the provisioning
     * service directly on approval and has no business in a resource, a log or a
     * debug dump.
     */
    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'status' => RecruitmentApplicationStatus::class,
            'date_of_birth' => 'date',
            'reviewed_at' => 'datetime',
            'password_hash' => 'hashed',
            // Same encryption as the employees table this field ends up in.
            'ghana_card_id' => 'encrypted',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('admin')
            // Deliberately not the HR fields or the phone — an activity log is
            // read by more people than the application itself.
            ->logOnly(['status', 'reviewed_by_user_id', 'created_user_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(RecruitmentLink::class, 'recruitment_link_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** The account this application became. Null unless approved. */
    public function createdUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', RecruitmentApplicationStatus::Pending);
    }

    /**
     * The HR and emergency-contact fields, ready to hand to hiring.
     *
     * @return array<string, mixed>
     */
    public function employeeDetails(): array
    {
        return array_filter([
            'ghana_card_id' => $this->ghana_card_id,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'nationality' => $this->nationality,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'emergency_contact_relationship' => $this->emergency_contact_relationship,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
