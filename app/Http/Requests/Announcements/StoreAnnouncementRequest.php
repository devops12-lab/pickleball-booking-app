<?php

namespace App\Http\Requests\Announcements;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class StoreAnnouncementRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'type' => ['sometimes', 'string', 'in:'.implode(',', AnnouncementType::values())],
            'audience' => ['sometimes', 'string', 'in:'.implode(',', AnnouncementAudience::values())],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => [
                'nullable',
                'date',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $publishAt = $this->date('publish_at');

                    if ($publishAt !== null && $value !== null && Carbon::parse($value)->lessThanOrEqualTo($publishAt)) {
                        $fail('The expiry must be after the publish time.');
                    }
                },
            ],
            'is_pinned' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Normalise validated payload keys.
     *
     * @return array<string, mixed>
     */
    public function validatedPayload(): array
    {
        $validated = $this->validated();

        if (! array_key_exists('type', $validated)) {
            $validated['type'] = AnnouncementType::General->value;
        }

        if (! array_key_exists('audience', $validated)) {
            $validated['audience'] = AnnouncementAudience::All->value;
        }

        if (! array_key_exists('is_pinned', $validated)) {
            $validated['is_pinned'] = false;
        }

        return $validated;
    }
}
