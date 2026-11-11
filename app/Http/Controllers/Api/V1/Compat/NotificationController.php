<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\AppNotification;
use App\Models\NotificationPreference;
use App\Support\Compat\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The SPA's notification inbox and per-category preferences
 * (packages/mocks/src/handlers-notifications.ts).
 */
class NotificationController extends Controller
{
    use ApiResponse;

    /**
     * `GET /notifications` — the latest 100, newest first, with the unread
     * count for that page.
     */
    public function index(Request $request): JsonResponse
    {
        $rows = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return $this->successResponse(data: [
            'items' => $rows->map(fn (AppNotification $row): array => NotificationService::payload($row))->values()->all(),
            'unread' => $rows->whereNull('read_at')->count(),
        ]);
    }

    /**
     * `POST /notifications/:id/read`
     */
    public function read(Request $request, string $notification): JsonResponse
    {
        $row = AppNotification::query()
            ->where('id', $notification)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($row === null) {
            return $this->errorResponse(
                message: "This notification doesn't exist.",
                code: Response::HTTP_NOT_FOUND,
            );
        }

        if ($row->read_at === null) {
            $row->read_at = now();
            $row->save();
        }

        return $this->successResponse(data: ['read' => (string) $row->id]);
    }

    /**
     * `POST /notifications/read-all`
     */
    public function readAll(Request $request): JsonResponse
    {
        $count = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->successResponse(data: ['read' => (int) $count]);
    }

    /**
     * `GET /notification-preferences`
     */
    public function preferences(Request $request): JsonResponse
    {
        return $this->successResponse(
            data: NotificationService::preferences((int) $request->user()->id),
        );
    }

    /**
     * `PUT /notification-preferences` — all five categories must be present
     * with both booleans, as the mock requires.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $input = $request->all();
        $errors = [];

        foreach (NotificationService::CATEGORIES as $category) {
            $value = $input[$category] ?? null;

            if (! is_array($value)
                || ! array_key_exists('in_app', $value)
                || ! array_key_exists('email', $value)
                || ! is_bool($value['in_app'])
                || ! is_bool($value['email'])) {
                $errors[$category] = ['Choose on or off for the app and email.'];
            }
        }

        if ($errors !== []) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $userId = (int) $request->user()->id;

        foreach (NotificationService::CATEGORIES as $category) {
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $userId, 'category' => $category],
                [
                    'in_app' => (bool) $input[$category]['in_app'],
                    'email' => (bool) $input[$category]['email'],
                ],
            );
        }

        return $this->successResponse(data: NotificationService::preferences($userId));
    }
}
