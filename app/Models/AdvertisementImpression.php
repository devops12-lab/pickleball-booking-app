<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvertisementImpression extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'advertisement_id',
        'user_id',
        'session_id',
        'ip_hash',
        'viewed_at',
        'placement',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
        ];
    }

    public function advertisement(): BelongsTo
    {
        return $this->belongsTo(Advertisement::class);
    }
}
