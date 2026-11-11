<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The public store front ("court front") of a facility tenant. Exactly one
 * row exists per tenant and it is created on first access.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $tagline
 * @property string|null $description
 * @property string|null $logo
 * @property string|null $cover_image
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $contact_address
 * @property string|null $website
 * @property string|null $facebook
 * @property string|null $instagram
 * @property bool $is_open
 *
 * @mixin \Eloquent
 */
class CourtFront extends Model
{
    use BelongsToTenant;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
        ];
    }

    /**
     * The tenant this store front presents.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
