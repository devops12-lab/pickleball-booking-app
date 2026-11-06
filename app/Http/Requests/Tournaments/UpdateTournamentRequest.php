<?php

namespace App\Http\Requests\Tournaments;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTournamentRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'format' => ['sometimes', 'string', 'in:single_elimination,double_elimination,round_robin'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
            'registration_deadline' => ['nullable', 'date'],
            'entry_fee' => ['nullable', 'numeric', 'min:0'],
            'max_teams' => ['nullable', 'integer', 'min:4'],
            'status' => ['sometimes', 'string', 'in:upcoming,registration_open,in_progress,completed,cancelled'],
        ];
    }
}
