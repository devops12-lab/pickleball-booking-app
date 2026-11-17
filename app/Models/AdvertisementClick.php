<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvertisementClick extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'advertisement_id',
        'user_id',
        'session_id',
        'ip_hash',
        'clicked_at',
        'destination_url',
    ];

    protected function casts(): array
    {
        return [
            'clicked_at' => 'datetime',
        ];
    }

    public function advertisement(): BelongsTo
    {
        return $this->belongsTo(Advertisement::class);
    }
}
