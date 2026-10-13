<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Enums\TeacherVerification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    use HasFactory;

    // Verification, merge and ordinal fields are deliberately not fillable:
    // only the identity services write them, so no request can approve an
    // identity by posting a field.
    protected $fillable = ['full_name', 'normalized_name', 'employment_status', 'department_id', 'source'];

    protected $attributes = [
        'verification_status' => 'pending',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $teacher) {
            $teacher->normalized_name = self::normalize($teacher->full_name);
        });
    }

    /**
     * A stable key for a written name. The sheets and the registrar disagree on
     * honorifics, middle initials and spacing for the same person, so identity
     * cannot be the raw string.
     */
    public static function normalize(?string $name): string
    {
        $name = mb_strtolower(trim((string) $name));
        $name = str_replace(['’', '‘'], "'", $name);
        $name = preg_replace('/,?\s*\b(sj|jr|sr|iii|ii)\b\.?/u', ' ', $name);
        $name = preg_replace('/^\s*(?:sch|br|fr|rev|ms|mr|mrs|bb|gng|dr)\.?\s+/u', ' ', $name);
        $name = preg_replace('/[^\p{L}\s-]/u', ' ', $name);
        $tokens = array_values(array_filter(
            preg_split('/\s+/', trim($name)) ?: [],
            fn ($t) => mb_strlen($t) > 1,
        ));

        return implode(' ', $tokens);
    }

    protected function casts(): array
    {
        return [
            'employment_status' => EmploymentStatus::class,
            'verification_status' => TeacherVerification::class,
            'verified_at' => 'datetime',
            'verification_evidence' => 'array',
            'chair_confirmed_at' => 'datetime',
            'merged_at' => 'datetime',
        ];
    }

    /** Teachers that have not been merged into another record. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('merged_into_teacher_id');
    }

    public function isMerged(): bool
    {
        return $this->merged_into_teacher_id !== null;
    }

    /** The record this one now lives on: itself unless it was merged away. */
    public function survivor(): self
    {
        $teacher = $this;
        $seen = [];

        while ($teacher->merged_into_teacher_id && ! isset($seen[$teacher->id])) {
            $seen[$teacher->id] = true;
            $teacher = self::findOrFail($teacher->merged_into_teacher_id);
        }

        return $teacher;
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_teacher_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function chairConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chair_confirmed_by_user_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(TeacherAlias::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(TeacherObservation::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function sectionAssignments(): HasMany
    {
        return $this->hasMany(TeacherSectionAssignment::class);
    }

    public function moderatorAssignments(): HasMany
    {
        return $this->hasMany(ClassModeratorAssignment::class);
    }

    public function honorsAssignments(): HasMany
    {
        return $this->hasMany(HonorsClassAssignment::class);
    }

    public function serviceLoads(): HasMany
    {
        return $this->hasMany(ServiceLoad::class);
    }

    public function otherAssignments(): HasMany
    {
        return $this->hasMany(TeacherOtherAssignment::class);
    }
}
