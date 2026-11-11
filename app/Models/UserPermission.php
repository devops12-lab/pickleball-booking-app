<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPermission extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'permission_id',
        'tenant_id',
        'is_revoked',
    ];

    protected function casts(): array
    {
        return [
            'is_revoked' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
