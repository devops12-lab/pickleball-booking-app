<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesPlatformAdmin;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Support\Compat\AdminView;
use App\Support\Compat\PlatformView;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The platform-wide contract: public settings and live announcements, plus the
 * Super Admin's settings, announcement desk and audit log.
 */
class PlatformController extends Controller
{
    use ApiResponse, AuthorizesPlatformAdmin;

    private const PAGE_SIZE = 25;

    private const AUDIENCES = ['players', 'owners', 'everyone'];

    private const TONES = ['info', 'warning'];

    private const AUDIENCE_WORDS = [
        'players' => 'players',
        'owners' => 'venue owners and staff',
        'everyone' => 'everyone',
    ];

    public function settings(): JsonResponse
    {
        return $this->successResponse(data: PlatformView::settings());
    }

    public function adminSettings(): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        return $this->successResponse(data: PlatformView::settings());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $errors = [];
        $trialInput = $request->input('trial_days');
        $trialDays = null;

        if (is_int($trialInput)) {
            $trialDays = $trialInput;
        } elseif (is_float($trialInput) && floor($trialInput) === $trialInput) {
            $trialDays = (int) $trialInput;
        } elseif (is_string($trialInput) && ctype_digit($trialInput)) {
            $trialDays = (int) $trialInput;
        }

        if ($trialDays === null || $trialDays < 7 || $trialDays > 60) {
            $errors['trial_days'] = ['Choose 7 to 60 days.'];
        }

        $email = mb_strtolower(trim((string) $request->input('support_email', '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['support_email'] = ['Enter a valid email.'];
        }

        $phone = trim((string) $request->input('support_phone', ''));
        if (preg_match('/^[0-9+][0-9 -]{6,16}$/', $phone) !== 1) {
            $errors['support_phone'] = ['Enter a phone number, like 0917 555 0100.'];
        }

        $signupsOpen = $request->input('venue_signups_open');
        if (! is_bool($signupsOpen)) {
            $errors['venue_signups_open'] = ['Choose open or closed.'];
        }

        if ($errors !== []) {
            return $this->validationError($errors);
        }

        $before = PlatformView::settings();
        $after = [
            'trial_days' => (int) $trialDays,
            'venue_signups_open' => $signupsOpen,
            'support_email' => $email,
            'support_phone' => $phone,
        ];

        if ($before !== $after) {
            $changes = [];

            if ($before['trial_days'] !== $after['trial_days']) {
                $changes[] = 'free trial '.$before['trial_days'].' → '.$after['trial_days'].' days';
            }
            if ($before['venue_signups_open'] !== $after['venue_signups_open']) {
                $changes[] = 'new venue sign-ups '.($after['venue_signups_open'] ? 'opened' : 'closed');
            }
            if ($before['support_email'] !== $after['support_email']) {
                $changes[] = 'support email '.$after['support_email'];
            }
            if ($before['support_phone'] !== $after['support_phone']) {
                $changes[] = 'support phone '.$after['support_phone'];
            }

            DB::transaction(function () use ($after, $changes): void {
                $rows = [
                    ['trial_days', (string) $after['trial_days'], 'integer'],
                    ['venue_signups_open', $after['venue_signups_open'] ? '1' : '0', 'boolean'],
                    ['support_email', $after['support_email'], 'string'],
                    ['support_phone', $after['support_phone'], 'string'],
                ];

                foreach ($rows as [$key, $value, $type]) {
                    PlatformSetting::query()->updateOrCreate(
                        ['key' => $key],
                        ['value' => $value, 'type' => $type, 'is_public' => true],
                    );
                }

                AdminView::writeAudit(
                    action: 'settings.updated',
                    summary: 'Changed settings: '.implode(', ', $changes),
                    type: 'settings',
                    id: 0,
                    name: 'Platform settings',
                    reason: null,
                );
            });
        }

        return $this->successResponse(data: PlatformView::settings(), message: 'Settings saved.');
    }

    public function announcements(): JsonResponse
    {
        $now = CarbonImmutable::now();
        $rank = ['live' => 0, 'scheduled' => 1, 'ended' => 2];

        $rows = Announcement::query()
            ->with('creator')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get()
            ->sort(function (Announcement $a, Announcement $b) use ($rank, $now): int {
                return $rank[PlatformView::announcementStatus($a, $now)]
                    <=> $rank[PlatformView::announcementStatus($b, $now)]
                    ?: $b->starts_at <=> $a->starts_at
                    ?: $b->id <=> $a->id;
            })
            ->values()
            ->map(fn (Announcement $announcement): array => PlatformView::announcement($announcement))
            ->all();

        return $this->successResponse(data: $rows);
    }

    public function adminAnnouncements(): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        return $this->announcements();
    }

    public function storeAnnouncement(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $input = $this->announcementInput($request);

        if ($input['errors'] !== []) {
            return $this->validationError($input['errors']);
        }

        $values = $input['values'];
        $announcement = Announcement::create([
            'title' => $values['title'],
            'message' => $values['message'],
            'audience' => $values['audience'],
            'tone' => $values['tone'],
            'starts_at' => $values['starts_at'],
            'ends_at' => $values['ends_at'],
            'created_by' => $request->user()->id,
        ]);

        AdminView::writeAudit(
            action: 'announcement.posted',
            summary: 'Posted "'.$announcement->title.'" for '.self::AUDIENCE_WORDS[$announcement->audience],
            type: 'announcement',
            id: (int) $announcement->id,
            name: (string) $announcement->title,
            reason: null,
        );

        return $this->successResponse(
            data: PlatformView::announcement($announcement->load('creator')),
            message: 'Announcement posted.',
            code: Response::HTTP_CREATED,
        );
    }

    public function updateAnnouncement(Request $request, string $announcement): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $row = Announcement::query()->with('creator')->find($announcement);

        if ($row === null) {
            return $this->errorResponse("This announcement doesn't exist.", code: Response::HTTP_NOT_FOUND);
        }

        if (PlatformView::announcementStatus($row) === 'ended') {
            return $this->errorResponse(
                'This announcement has ended. Post a new one instead.',
                code: Response::HTTP_CONFLICT,
            );
        }

        $input = $this->announcementInput($request, $row);

        if ($input['errors'] !== []) {
            return $this->validationError($input['errors']);
        }

        $values = $input['values'];
        $row->update([
            'title' => $values['title'],
            'message' => $values['message'],
            'audience' => $values['audience'],
            'tone' => $values['tone'],
            'starts_at' => $values['starts_at'],
            'ends_at' => $values['ends_at'],
        ]);

        AdminView::writeAudit(
            action: 'announcement.edited',
            summary: 'Edited "'.$row->title.'"',
            type: 'announcement',
            id: (int) $row->id,
            name: (string) $row->title,
            reason: null,
        );

        return $this->successResponse(
            data: PlatformView::announcement($row->fresh()->load('creator')),
            message: 'Announcement saved.',
        );
    }

    public function endAnnouncement(string $announcement): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $row = Announcement::query()->with('creator')->find($announcement);

        if ($row === null) {
            return $this->errorResponse("This announcement doesn't exist.", code: Response::HTTP_NOT_FOUND);
        }

        if (PlatformView::announcementStatus($row) === 'ended') {
            return $this->errorResponse(
                'This announcement has already ended.',
                code: Response::HTTP_CONFLICT,
            );
        }

        $now = CarbonImmutable::now();
        $row->update([
            'ends_at' => $now,
            'starts_at' => $row->starts_at->greaterThan($now) ? $now : $row->starts_at,
        ]);

        AdminView::writeAudit(
            action: 'announcement.ended',
            summary: 'Ended "'.$row->title.'"',
            type: 'announcement',
            id: (int) $row->id,
            name: (string) $row->title,
            reason: null,
        );

        return $this->successResponse(
            data: PlatformView::announcement($row->fresh()->load('creator')),
            message: 'Announcement ended.',
        );
    }

    public function publicAnnouncements(Request $request): JsonResponse
    {
        $audience = (string) $request->query('for', '');

        if (! in_array($audience, ['players', 'owners'], true)) {
            return $this->validationError(['for' => ['Choose players or owners.']]);
        }

        $now = CarbonImmutable::now();
        $rows = Announcement::query()
            ->with('creator')
            ->where('starts_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->whereIn('audience', [$audience, 'everyone'])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Announcement $announcement): array => PlatformView::announcement($announcement))
            ->all();

        return $this->successResponse(data: $rows);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $q = trim((string) $request->query('q', ''));
        $type = trim((string) $request->query('type', ''));
        $page = max(1, (int) $request->query('page', 1));

        $query = AuditLog::query()->with('user');

        if ($type === 'billing') {
            $this->whereBilling($query);
        } elseif ($type === 'competition') {
            $query->where(function ($where): void {
                $where->where('action', 'like', 'match.%')
                    ->orWhere('action', 'like', 'rating.%');
            });
        } elseif ($type !== '') {
            $query->where(function ($where): void {
                $where->where('action', 'not like', 'subscription.%')
                    ->where('action', 'not like', 'invoice.%')
                    ->where('action', 'not like', 'match.%')
                    ->where('action', 'not like', 'rating.%');
            });

            $entityTypes = match ($type) {
                'venue' => ['tenant', 'venue'],
                'club' => ['club'],
                'user' => ['user'],
                'plan' => ['plan'],
                'announcement' => ['announcement'],
                'settings' => ['settings'],
                default => null,
            };

            if ($entityTypes === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('entity_type', $entityTypes);
            }
        }

        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($where) use ($like): void {
                $where->where('new_values', 'like', $like)
                    ->orWhereHas('user', function ($user) use ($like): void {
                        $user->where('name', 'like', $like)->orWhere('email', 'like', $like);
                    });
            });
        }

        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);

        $entries = $query
            ->orderByDesc('id')
            ->forPage($page, self::PAGE_SIZE)
            ->get()
            ->map(fn (AuditLog $log): array => AdminView::auditEntry($log))
            ->values()
            ->all();

        return $this->successResponse(data: [
            'entries' => $entries,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<AuditLog>  $query
     */
    private function whereBilling($query): void
    {
        $query->where(function ($where): void {
            $where->where('action', 'like', 'subscription.%')
                ->orWhere('action', 'like', 'invoice.%');
        });
    }

    /**
     * @return array{errors: array<string, list<string>>, values: array<string, mixed>}
     */
    private function announcementInput(Request $request, ?Announcement $existing = null): array
    {
        $errors = [];
        $title = trim((string) $request->input('title', ''));
        $message = trim((string) $request->input('message', ''));
        $audience = (string) $request->input('audience', '');
        $tone = (string) $request->input('tone', '');

        if (mb_strlen($title) < 3 || mb_strlen($title) > 80) {
            $errors['title'] = ['Use 3 to 80 characters.'];
        }
        if (mb_strlen($message) < 10 || mb_strlen($message) > 280) {
            $errors['message'] = ['Use 10 to 280 characters.'];
        }
        if (! in_array($audience, self::AUDIENCES, true)) {
            $errors['audience'] = ['Choose who sees it.'];
        }
        if (! in_array($tone, self::TONES, true)) {
            $errors['tone'] = ['Choose information or warning.'];
        }

        $now = CarbonImmutable::now();
        $startsInput = $request->input('starts_at');
        $starts = $existing === null
            ? $now
            : CarbonImmutable::instance($existing->starts_at);

        if ($startsInput !== null && $startsInput !== '') {
            if (! is_string($startsInput)) {
                $errors['starts_at'] = ['Enter a valid start.'];
            } else {
                try {
                    $starts = CarbonImmutable::parse($startsInput);
                } catch (Throwable) {
                    $errors['starts_at'] = ['Enter a valid start.'];
                }
            }
        }

        $endsInput = $request->input('ends_at');
        $ends = null;

        if ($endsInput !== null && $endsInput !== '') {
            if (! is_string($endsInput)) {
                $errors['ends_at'] = ['Enter a valid end.'];
            } else {
                try {
                    $ends = CarbonImmutable::parse($endsInput);
                } catch (Throwable) {
                    $errors['ends_at'] = ['Enter a valid end.'];
                }
            }
        }

        if ($ends !== null && $ends->lessThanOrEqualTo($starts)) {
            $errors['ends_at'] = ['End after it starts.'];
        } elseif ($ends !== null && $ends->lessThanOrEqualTo($now)) {
            $errors['ends_at'] = ['End in the future.'];
        }

        return [
            'errors' => $errors,
            'values' => [
                'title' => $title,
                'message' => $message,
                'audience' => $audience,
                'tone' => $tone,
                'starts_at' => $starts,
                'ends_at' => $ends,
            ],
        ];
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validationError(array $errors): JsonResponse
    {
        return $this->errorResponse(
            message: 'The given data was invalid.',
            errors: $errors,
            code: Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
