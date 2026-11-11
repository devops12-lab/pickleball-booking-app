<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RestoreUploadedDatabaseBackupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('database_backups.restore') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'extensions:sql',
                'max:'.(int) config('database-backup.max_upload_size', 51200),
            ],
            'confirmation' => ['required', 'string', 'max:255'],
            'acknowledge' => ['required', 'accepted'],
        ];
    }
}
