<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RatingRule extends Model
{
    protected $fillable = [
        'initial',
        'provisional_matches',
        'k_provisional',
        'k_established',
    ];

    protected function casts(): array
    {
        return [
            'initial' => 'integer',
            'provisional_matches' => 'integer',
            'k_provisional' => 'integer',
            'k_established' => 'integer',
        ];
    }
}
