<?php

namespace App\Models;

use App\Enums\TenantPaymentMethodType;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment channel a facility accepts for reservations: cash, e-wallets
 * (GCash, Maya), QRPh or bank transfers. Managed by the facility owner.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property TenantPaymentMethodType $type
 * @property string|null $account_name
 * @property string|null $account_number
 * @property string|null $instructions
 * @property string|null $qr_image
 * @property bool $is_active
 * @property int $sort_order
 *
 * @mixin \Eloquent
 */
class TenantPaymentMethod extends Model
{
    use BelongsToTenant;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'account_name',
        'account_number',
        'instructions',
        'qr_image',
        'is_active',
        'sort_order',
    ];

    /**
     * Default model attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TenantPaymentMethodType::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
