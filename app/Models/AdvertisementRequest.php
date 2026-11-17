<?php

namespace App\Models;

use App\Enums\AdvertisementRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvertisementRequest extends Model
{
    protected $fillable = [
        'requester_id',
        'requester_type',
        'tenant_id',
        'title',
        'description',
        'objective',
        'requested_placement',
        'requested_start_date',
        'requested_end_date',
        'contact_email',
        'contact_phone',
        'additional_requirements',
        'attachment_path',
        'status',
        'admin_response',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AdvertisementRequestStatus::class,
            'requested_start_date' => 'date',
            'requested_end_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
