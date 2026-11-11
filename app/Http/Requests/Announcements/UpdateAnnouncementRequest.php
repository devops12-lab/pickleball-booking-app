<?php

namespace App\Http\Requests\Announcements;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class UpdateAnnouncementRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:200'],
            'body' => ['sometimes', 'string', 'max:20000'],
            'type' => ['sometimes', 'string', 'in:'.implode(',', AnnouncementType::values())],
            'audience' => ['sometimes', 'string', 'in:'.implode(',', AnnouncementAudience::values())],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => [
                'nullable',
                'date',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $publishAt = $this->date('publish_at') ?? $this->route('announcement')?->publish_at;

                    if ($publishAt !== null && $value !== null && Carbon::parse($value)->lessThanOrEqualTo($publishAt)) {
                        $fail('The expiry must be after the publish time.');
                    }
                },
            ],
            'is_pinned' => ['sometimes', 'boolean'],
        ];
    }
}
