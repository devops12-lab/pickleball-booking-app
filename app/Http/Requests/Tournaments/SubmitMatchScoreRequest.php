<?php

namespace App\Http\Requests\Tournaments;

use Illuminate\Foundation\Http\FormRequest;

class SubmitMatchScoreRequest extends FormRequest
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
            'score' => ['required', 'string', 'max:50'],
            'winner_id' => ['required', 'integer', 'exists:tournament_participants,id'],
            'scores_detail' => ['nullable', 'array'],
        ];
    }
}
