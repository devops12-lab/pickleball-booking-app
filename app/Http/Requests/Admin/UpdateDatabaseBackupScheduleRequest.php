<?php

namespace App\Http\Requests\Admin;

use App\Services\DatabaseBackupScheduleService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

class UpdateDatabaseBackupScheduleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('database_backups.manage_schedule') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $schedules = app(DatabaseBackupScheduleService::class);

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'is_enabled' => ['sometimes', 'boolean'],
            'execution_time' => ['sometimes', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'day_of_week' => [
                'sometimes',
                function (string $attribute, mixed $value, Closure $fail) use ($schedules): void {
                    try {
                        $schedules->normalizeDayOfWeek($value);
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
            'day_of_month' => [
                'sometimes',
                function (string $attribute, mixed $value, Closure $fail) use ($schedules): void {
                    try {
                        $schedules->normalizeDayOfMonth($value);
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'retention_count' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ];
    }
}
