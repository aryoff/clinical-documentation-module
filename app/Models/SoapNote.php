<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\ClinicalDocumentation\DTOs\VitalsData;
use Modules\HospitalCore\Models\Registration;

class SoapNote extends Model
{
    use SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $table = 'cd_soap_notes';

    protected $fillable = [
        'registration_id',
        'author_id',
        'author_role',
        'status',
        'amended_from_id',
        'subjective',
        'objective',
        'assessment',
        'plan',
        'vitals',
        'noted_at',
        'submitted_at',
        'created_by',
    ];

    protected $casts = [
        'vitals' => VitalsData::class,
        'noted_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the registration associated with the SOAP note.
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /**
     * Get the author of the SOAP note.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Get the creator of the SOAP note.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the original SOAP note that this note amends.
     */
    public function amendedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'amended_from_id');
    }

    /**
     * Get the amendments/addendums created from this SOAP note.
     */
    public function amendments(): HasMany
    {
        return $this->hasMany(self::class, 'amended_from_id');
    }

    /**
     * Get the soft-linked prescriptions for this SOAP note.
     */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(\Modules\EPrescription\Models\Prescription::class, 'soap_note_id');
    }
}
