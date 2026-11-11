<?php

namespace App\Http\Requests\Tournaments;

use Illuminate\Foundation\Http\FormRequest;

class RegisterTournamentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tournament_category_id' => ['nullable', 'integer', 'exists:tournament_categories,id'],
            'partner_id' => ['nullable', 'integer', 'exists:users,id'],
            'team_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
