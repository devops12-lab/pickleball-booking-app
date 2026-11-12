<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Club;
use App\Models\Court;
use App\Models\OpenPlayFeePayment;
use App\Models\OpenPlayFeeRefund;
use App\Models\OpenPlaySession;
use App\Models\Tenant;
use App\Services\BookingAvailabilityService;
use App\Support\Compat\Contract;
use App\Support\Compat\NotificationService;
use App\Support\Compat\PlayerBookingView;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Open play (§9.6): a session is hosted by a venue or a community club, on one
 * or more courts, and players register against it.
 */
class OpenPlayController extends Controller
{
    use ApiResponse;

    private const SESSION_TYPES = ['open_play', 'beginner', 'intermediate', 'advanced', 'competitive', 'social', 'tournament'];

    private const SKILLS = ['all', 'beginner', 'novice', 'intermediate', 'advanced', 'pro'];

    public function __construct(private readonly BookingAvailabilityService $availability) {}

    public function index(Request $request): JsonResponse
    {
        $viewer = $this->viewerId($request);
        $scope = (string) $request->query('scope', 'upcoming');

        if (! in_array($scope, ['upcoming', 'joined', 'hosted', 'past'], true)) {
            return $this->fieldError('scope', 'Choose upcoming, joined, hosted or past.');
        }

        $level = $request->query('level');

        if ($level !== null && ! in_array($level, self::SKILLS, true)) {
            return $this->fieldError('level', 'Choose a skill level.');
        }

        $search = mb_strtolower(trim((string) $request->query('search', '')));

        $query = OpenPlaySession::query()
            ->with(['court', 'courts', 'host', 'tenant.venueProfile', 'tenant.subscription', 'organizerClub', 'participants.user'])
            ->where('status', '!=', 'cancelled');

        // Narrow the window so old completed sessions don't pile up.
        if ($scope === 'past') {
            $query->where('ends_at', '<=', now())
                ->where('ends_at', '>=', now()->subDays(90));
        } elseif ($scope === 'upcoming') {
            $query->where(function ($q): void {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            });
        }

        $sessions = $query->latest('starts_at')->limit(500)->get()
            ->filter(function (OpenPlaySession $session) use ($viewer, $scope): bool {
                $status = Contract::openPlayStatus($session);
                $joined = $viewer !== null && $session->participants
                    ->contains(fn ($p) => (int) $p->user_id === $viewer && $p->status !== 'cancelled');
                $hosted = $viewer !== null
                    && ((int) $session->host_id === $viewer || Contract::canManageOpenPlay($session, $viewer));

                return match ($scope) {
                    'joined' => $joined && $status !== 'completed' && $status !== 'rejected',
                    'hosted' => $hosted && $status !== 'completed',
                    'past' => $status === 'completed' && ($joined || $hosted),
                    default => in_array($status, ['published', 'live'], true),
                };
            })
            ->filter(fn (OpenPlaySession $session) => $level === null
                || $session->skill_level === $level
                || ! filled($session->skill_level)
                || $session->skill_level === 'all')
            ->filter(fn (OpenPlaySession $session) => $search === ''
                || str_contains(mb_strtolower((string) $session->title), $search)
                || str_contains(mb_strtolower((string) $session->tenant?->name), $search))
            ->sortBy(fn (OpenPlaySession $session) => $session->starts_at->getTimestamp(), SORT_REGULAR, $scope === 'past')
            ->values();

        return $this->successResponse(
            data: $sessions->map(fn (OpenPlaySession $session) => Contract::openPlaySession($session, $viewer))->all()
        );
    }

    public function show(Request $request, OpenPlaySession $session): JsonResponse
    {
        $viewer = $this->viewerId($request);
        $session->load(['court', 'courts', 'host', 'tenant.venueProfile', 'tenant.subscription', 'organizerClub', 'participants.user']);

        $status = Contract::openPlayStatus($session);
        $manage = $viewer !== null && Contract::canManageOpenPlay($session, $viewer);
        $member = $viewer !== null && $session->participants
            ->contains(fn ($p) => (int) $p->user_id === $viewer && $p->status !== 'cancelled');

        // Visitors only see sessions that are open; players keep access to their own past games.
        if (! in_array($status, ['published', 'live'], true) && ! $manage && ! $member) {
            return $this->errorResponse(message: 'Session not found.', code: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(data: Contract::openPlayDetail($session, $viewer));
    }

    /** Resolve the signed-in player from either guard used by the SPA. */
    private function viewerId(Request $request): ?int
    {
        $viewer = $request->user() ?? Auth::guard('web')->user();

        return $viewer === null ? null : (int) $viewer->id;
    }

    public function store(Request $request): JsonResponse
    {
        $checked = $this->checkPayload($request, null);

        if (isset($checked['error'])) {
            return $checked['error'];
        }

        $session = OpenPlaySession::create([
            'tenant_id' => $checked['venue']->id,
            'court_id' => $checked['courts'][0]->id,
            'organizer_type' => $checked['type'],
            'organizer_id' => $checked['type'] === 'community' ? $checked['club']->id : $checked['venue']->id,
            'host_id' => $request->user()->id,
            'title' => $checked['title'],
            'description' => $checked['description'],
            'session_type' => $checked['session_type'],
            'skill_level' => $checked['skill_level'],
            'starts_at' => $checked['starts']->setTimezone('UTC')->toDateTimeString(),
            'ends_at' => $checked['ends']->setTimezone('UTC')->toDateTimeString(),
            'registration_deadline' => $checked['deadline']->setTimezone('UTC')->toDateTimeString(),
            'max_participants' => $checked['capacity'],
            'fee' => $checked['fee'],
            'member_fee' => $checked['member_fee'],
            'members_only' => $checked['members_only'],
            'court_fee' => $this->courtFeeFor($checked['type'], $checked['courts'], $checked['starts'], $checked['ends']),
            'status' => $checked['status'],
        ]);

        $session->courts()->sync($checked['courts']->pluck('id')->all());

        return $this->successResponse(
            data: Contract::openPlayDetail($session->fresh(), (int) $request->user()->id),
            code: Response::HTTP_CREATED,
        );
    }

    public function update(Request $request, OpenPlaySession $session): JsonResponse
    {
        $viewer = (int) $request->user()->id;

        if (! Contract::canManageOpenPlay($session, $viewer)) {
            return $this->errorResponse(
                message: "Only the club's admins can change this session.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        if (in_array(Contract::openPlayStatus($session), ['cancelled', 'completed'], true)) {
            return $this->errorResponse(
                message: 'This session has already finished, so it cannot be changed.',
                code: Response::HTTP_CONFLICT,
            );
        }

        $checked = $this->checkPayload($request, $session);

        if (isset($checked['error'])) {
            return $checked['error'];
        }

        $session->update([
            'tenant_id' => $checked['venue']->id,
            'court_id' => $checked['courts'][0]->id,
            'organizer_type' => $checked['type'],
            'organizer_id' => $checked['type'] === 'community' ? $checked['club']->id : $checked['venue']->id,
            'title' => $checked['title'],
            'description' => $checked['description'],
            'session_type' => $checked['session_type'],
            'skill_level' => $checked['skill_level'],
            'starts_at' => $checked['starts']->setTimezone('UTC')->toDateTimeString(),
            'ends_at' => $checked['ends']->setTimezone('UTC')->toDateTimeString(),
            'registration_deadline' => $checked['deadline']->setTimezone('UTC')->toDateTimeString(),
            'max_participants' => $checked['capacity'],
            'fee' => $checked['fee'],
            'member_fee' => $checked['member_fee'],
            'members_only' => $checked['members_only'],
            'court_fee' => $this->courtFeeFor($checked['type'], $checked['courts'], $checked['starts'], $checked['ends']),
        ]);

        $session->courts()->sync($checked['courts']->pluck('id')->all());

        return $this->successResponse(data: Contract::openPlayDetail($session->fresh(), $viewer));
    }

    /**
     * Validate a create/edit payload the way the app expects, with every field
     * error at once.
     *
     * @return array<string, mixed>
     */
    private function checkPayload(Request $request, ?OpenPlaySession $existing): array
    {
        $viewer = (int) $request->user()->id;
        $type = (string) $request->input('organizer_type', 'venue');
        $organizerId = (int) $request->input('organizer_id');
        $errors = [];

        $tenant = null;
        $club = null;

        if ($type === 'venue') {
            $tenant = Tenant::query()->with(['venueProfile', 'subscription'])->find($organizerId);

            if ($tenant === null) {
                return ['error' => $this->fieldError('organizer_id', 'Choose a club to host as.')];
            }

            if (! DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $viewer)->exists()) {
                return ['error' => $this->errorResponse(
                    message: "Only the club's admins can host sessions for it.",
                    code: Response::HTTP_FORBIDDEN,
                )];
            }
        } elseif ($type === 'community') {
            $club = Club::query()->find($organizerId);

            if ($club === null) {
                return ['error' => $this->fieldError('organizer_id', 'Choose a club to host as.')];
            }

            $manages = (int) $club->creator_id === $viewer
                || DB::table('club_members')
                    ->where('club_id', $club->id)
                    ->where('user_id', $viewer)
                    ->whereIn('role', ['owner', 'admin'])
                    ->where('status', 'active')
                    ->exists();

            if (! $manages) {
                return ['error' => $this->errorResponse(
                    message: "Only the club's admins can host sessions for it.",
                    code: Response::HTTP_FORBIDDEN,
                )];
            }
        } else {
            return ['error' => $this->fieldError('organizer_id', 'Choose a club to host as.')];
        }

        $venue = Tenant::query()
            ->with(['venueProfile', 'subscription', 'courts.priceRules', 'courts.operatingHours'])
            ->find((int) $request->input('venue_id'));

        if ($venue === null) {
            $errors['venue_id'][] = 'Choose a venue.';
        } elseif ($type === 'venue' && $venue->id !== $organizerId) {
            $errors['venue_id'][] = "A venue's own sessions are played on its own courts.";
        } else {
            $settings = Contract::settings($venue->venueProfile);
            $status = Contract::club($venue)['status'];

            if ($status !== 'active') {
                $errors['venue_id'][] = "{$venue->name} isn't taking bookings right now.";
            } elseif ($type === 'community' && ! ($settings['allow_player_open_play'] ?? true)) {
                $errors['venue_id'][] = "{$venue->name} doesn't host community sessions. Choose another venue.";
            }
        }

        $title = trim((string) $request->input('title', ''));

        if ($title === '') {
            $errors['title'][] = 'Give the session a name players will recognise.';
        }

        $timezone = (string) ($venue?->venueProfile?->timezone ?: 'Asia/Manila');

        try {
            $starts = CarbonImmutable::parse((string) $request->input('starts_at'))->setTimezone($timezone);
            $ends = CarbonImmutable::parse((string) $request->input('ends_at'))->setTimezone($timezone);
        } catch (\Throwable) {
            return ['error' => $this->fieldError('starts_at', 'Choose when the session starts.')];
        }

        $minutes = ($ends->getTimestamp() - $starts->getTimestamp()) / 60;

        if ($minutes <= 0) {
            $errors['ends_at'][] = 'The session must end after it starts.';
        } elseif ($minutes < 60 || $minutes > 360) {
            $errors['ends_at'][] = 'Sessions run between 1 and 6 hours.';
        } elseif ($existing === null && $starts->isPast()) {
            $errors['starts_at'][] = 'That time has already passed.';
        }

        $rawDeadline = $request->input('registration_deadline');

        try {
            $deadline = filled($rawDeadline) ? CarbonImmutable::parse((string) $rawDeadline)->setTimezone($timezone) : $starts;
        } catch (\Throwable) {
            $deadline = $starts;
            $errors['registration_deadline'][] = 'Registration has to close at or before the start time.';
        }

        if ($deadline->greaterThan($starts)) {
            $errors['registration_deadline'][] = 'Registration has to close at or before the start time.';
        }

        $capacity = (int) $request->input('capacity');
        $activeCount = $existing === null
            ? 0
            : $existing->participants()->whereIn('status', ['registered', 'checked_in', 'completed'])->count();

        if ($capacity < 4 || $capacity > 64) {
            $errors['capacity'][] = 'Choose between 4 and 64 players.';
        } elseif ($capacity < $activeCount) {
            $errors['capacity'][] = "{$activeCount} players are already registered. Capacity can't go below that.";
        }

        $fee = (string) $request->input('fee', '0');
        $memberFee = (string) $request->input('member_fee', '0');
        $money = '/^\d+(\.\d{1,2})?$/';

        if (preg_match($money, $fee) !== 1) {
            $errors['fee'][] = 'Enter the fee in pesos, or 0 for free.';
        }

        if (preg_match($money, $memberFee) !== 1) {
            $errors['member_fee'][] = 'Enter the member fee in pesos, or 0.';
        } elseif ((float) $memberFee > (float) $fee) {
            $errors['member_fee'][] = "Members can't pay more than everyone else.";
        }

        $courtIds = $request->input('court_ids');
        $courts = collect();

        if (! is_array($courtIds) || $courtIds === []) {
            $errors['court_ids'][] = 'Choose at least one court.';
        } elseif ($venue !== null) {
            $courts = Court::query()
                ->with(['priceRules', 'operatingHours'])
                ->where('tenant_id', $venue->id)
                ->whereIn('id', array_map('intval', $courtIds))
                ->get();

            if ($courts->count() !== count(array_unique(array_map('intval', $courtIds)))) {
                $errors['court_ids'][] = 'Choose courts at this venue.';
            } elseif (! isset($errors['ends_at']) && ! isset($errors['starts_at'])) {
                foreach ($courts as $court) {
                    if (! $this->availability->isAvailable(
                        $court,
                        $starts->toDateTimeString(),
                        $ends->toDateTimeString(),
                        null,
                        $timezone,
                    )) {
                        $errors['court_ids'][] = "{$court->name} is already taken at that time.";

                        break;
                    }
                }
            }
        }

        $sessionType = (string) $request->input('session_type', 'open_play');
        $skill = (string) $request->input('skill_level', 'all');

        if (! in_array($sessionType, self::SESSION_TYPES, true)) {
            $errors['session_type'][] = 'Choose a session type.';
        }

        if (! in_array($skill, self::SKILLS, true)) {
            $errors['skill_level'][] = 'Choose a skill level.';
        }

        if ($errors !== []) {
            return ['error' => $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            )];
        }

        $status = $existing?->status ?? 'published';

        if ($existing === null && $type === 'community') {
            $settings = Contract::settings($venue->venueProfile);
            $status = ($settings['require_open_play_approval'] ?? false) ? 'pending_approval' : 'published';
        }

        return [
            'type' => $type,
            'club' => $club,
            'venue' => $venue,
            'courts' => $courts,
            'title' => $title,
            'description' => trim((string) $request->input('description', '')),
            'session_type' => $sessionType,
            'skill_level' => $skill,
            'starts' => $starts,
            'ends' => $ends,
            'deadline' => $deadline,
            'capacity' => $capacity,
            'fee' => $fee,
            'member_fee' => $memberFee,
            'members_only' => (bool) $request->input('members_only', false),
            'status' => $status,
        ];
    }

    /**
     * A venue's public session list, or a community's (signed in only).
     */
    public function organizerSessions(Request $request, string $type, int $id): JsonResponse
    {
        if (! in_array($type, ['venue', 'community'], true)) {
            return $this->errorResponse(message: 'Club not found.', code: Response::HTTP_NOT_FOUND);
        }

        $viewer = $request->user()?->id !== null ? (int) $request->user()->id : null;
        $tenant = $type === 'venue'
            ? Tenant::query()->with(['venueProfile', 'subscription'])->find($id)
            : null;
        $club = $type === 'community' ? Club::query()->find($id) : null;

        if ($tenant === null && $club === null) {
            return $this->errorResponse(message: 'Club not found.', code: Response::HTTP_NOT_FOUND);
        }

        if ($type === 'community' && $viewer === null) {
            return $this->errorResponse(
                message: 'Sign in to view this club\'s sessions.',
                code: Response::HTTP_UNAUTHORIZED,
            );
        }

        $when = (string) $request->query('when', 'upcoming');

        if (! in_array($when, ['upcoming', 'pending', 'past'], true)) {
            return $this->fieldError('when', 'Choose upcoming, pending or past.');
        }

        $manage = $viewer !== null && ($type === 'venue'
            ? DB::table('tenant_users')->where('tenant_id', $id)->where('user_id', $viewer)->exists()
            : ((int) $club?->creator_id === $viewer
                || DB::table('club_members')
                    ->where('club_id', $id)
                    ->where('user_id', $viewer)
                    ->whereIn('role', ['owner', 'admin'])
                    ->where('status', 'active')
                    ->exists()));

        $sessions = OpenPlaySession::query()
            ->with(['court', 'courts', 'host', 'tenant.venueProfile', 'tenant.subscription', 'organizerClub', 'participants.user'])
            ->where($type === 'venue'
                ? ['tenant_id' => $id]
                : ['organizer_type' => 'community', 'organizer_id' => $id])
            ->get()
            ->filter(function (OpenPlaySession $session) use ($when, $manage): bool {
                $status = Contract::openPlayStatus($session);

                return match ($when) {
                    'pending' => $status === 'pending_approval' && $manage,
                    'past' => $status === 'completed'
                        || (in_array($status, ['cancelled', 'rejected'], true) && $manage),
                    default => in_array($status, ['published', 'live'], true)
                        || ($status === 'pending_approval' && $manage),
                };
            })
            ->sortBy(fn (OpenPlaySession $session) => $session->starts_at->getTimestamp(), SORT_REGULAR, $when === 'past')
            ->values();

        return $this->successResponse(
            data: $sessions->map(fn (OpenPlaySession $session) => Contract::openPlaySession($session, $viewer))->all()
        );
    }

    /**
     * Player actions on a session: join, waitlist, leave.
     * Host actions: cancel, approve, reject.
     */
    public function act(Request $request, OpenPlaySession $session, string $action): JsonResponse
    {
        $viewer = (int) $request->user()->id;
        $session->load(['participants.user', 'courts', 'court', 'host', 'tenant.venueProfile', 'tenant.subscription', 'organizerClub']);
        $status = Contract::openPlayStatus($session);
        $mine = $session->participants
            ->first(fn ($p) => (int) $p->user_id === $viewer && $p->status !== 'cancelled');
        $reason = trim((string) $request->input('reason', ''));

        switch ($action) {
            case 'join':
            case 'waitlist':
                if (! in_array($status, ['published', 'live'], true)) {
                    return $this->errorResponse(
                        message: "This session isn't open for registration.",
                        code: Response::HTTP_CONFLICT,
                    );
                }

                $deadline = $session->registration_deadline ?? $session->starts_at;

                if (now()->greaterThan($deadline)) {
                    return $this->errorResponse(
                        message: 'Registration for this session has closed.',
                        code: Response::HTTP_CONFLICT,
                    );
                }

                $organizer = Contract::openPlaySession($session, $viewer)['organizer'];
                $membership = $this->membership($session, $viewer);

                if ($membership !== null && $membership->status === 'suspended') {
                    return $this->errorResponse(
                        message: "Your membership with {$organizer['name']} is suspended.",
                        code: Response::HTTP_FORBIDDEN,
                    );
                }

                $member = $this->isMember($session, $viewer);

                if ($session->members_only && ! $member) {
                    return $this->errorResponse(
                        message: "This session is for {$organizer['name']} members. Join the club first.",
                        code: Response::HTTP_FORBIDDEN,
                    );
                }

                if ($mine !== null) {
                    return $this->errorResponse(
                        message: $mine->status === 'waitlisted'
                            ? "You're already on the waitlist (number {$mine->waitlist_position})."
                            : "You're already registered for this session.",
                        code: Response::HTTP_CONFLICT,
                    );
                }

                // Lock the session row to prevent over-capacity under concurrency.
                $result = DB::transaction(function () use ($session, $action, $viewer, $member) {
                    OpenPlaySession::lockForUpdate()->find($session->id);

                    $active = $this->activeCount($session);
                    $full = $active >= (int) $session->max_participants;

                    if ($action === 'join' && $full) {
                        return 'full';
                    }

                    if ($action === 'waitlist' && ! $full) {
                        return 'not_full';
                    }

                    $fee = $member ? (string) $session->member_fee : (string) $session->fee;

                    $fields = [
                        'status' => $full ? 'waitlisted' : 'registered',
                        'waitlist_position' => $full
                            ? $session->participants()->where('status', 'waitlisted')->count() + 1
                            : null,
                        'fee_due' => $full ? 0 : $fee,
                        'fee_status' => $full || (float) $fee <= 0 ? 'free' : 'unpaid',
                        'is_member' => $member,
                        'checked_in_at' => null,
                        'queue_state' => null,
                        'games_played' => 0,
                    ];

                    $returning = $session->participants()
                        ->where('user_id', $viewer)
                        ->where('status', 'cancelled')
                        ->first();

                    if ($returning !== null) {
                        $returning->update($fields);
                    } else {
                        $session->participants()->create(['user_id' => $viewer] + $fields);
                    }

                    return 'ok';
                });

                if ($result === 'full') {
                    return $this->errorResponse(
                        message: 'This session is full. Join the waitlist to get the next open spot.',
                        code: Response::HTTP_CONFLICT,
                    );
                }

                if ($result === 'not_full') {
                    return $this->errorResponse(
                        message: 'There are open spots. Register instead.',
                        code: Response::HTTP_CONFLICT,
                    );
                }

                break;

            case 'leave':
                if ($mine === null) {
                    return $this->errorResponse(
                        message: "You're not registered for this session.",
                        code: Response::HTTP_NOT_FOUND,
                    );
                }

                if (! in_array($mine->status, ['registered', 'waitlisted'], true)) {
                    return $this->errorResponse(
                        message: "You've already checked in. Ask the host if you need to leave.",
                        code: Response::HTTP_CONFLICT,
                    );
                }

                $hours = $this->cancellationHours($session);

                if ($mine->status === 'registered'
                    && now()->greaterThan($session->starts_at->toImmutable()->subHours($hours))) {
                    return $this->errorResponse(
                        message: "Cancellations closed {$hours} hours before the start. Message the host.",
                        code: Response::HTTP_CONFLICT,
                    );
                }

                DB::transaction(function () use ($mine, $session): void {
                    $mine->update([
                        'status' => 'cancelled',
                        'waitlist_position' => null,
                        'queue_state' => null,
                        'queue_position' => null,
                        'queue_since' => null,
                    ]);
                    $this->promoteFromWaitlist($session);
                    $this->renumberWaitlist($session);
                });

                break;

            case 'cancel':
                if ($session->host_id !== $viewer && ! Contract::canManageOpenPlay($session, $viewer)) {
                    return $this->errorResponse(
                        message: "Only the session's organizers can cancel it.",
                        code: Response::HTTP_FORBIDDEN,
                    );
                }

                if (! in_array($status, ['published', 'pending_approval'], true)) {
                    return $this->errorResponse(
                        message: 'Only upcoming sessions can be cancelled.',
                        code: Response::HTTP_CONFLICT,
                    );
                }

                if ($reason === '') {
                    return $this->fieldError('reason', 'Add a reason. Registered players see it.');
                }

                $session->update(['status' => 'cancelled', 'status_reason' => $reason]);

                break;

            case 'approve':
            case 'reject':
                if (! DB::table('tenant_users')->where('tenant_id', $session->tenant_id)->where('user_id', $viewer)->exists()) {
                    return $this->errorResponse(
                        message: "Only the venue's managers can approve sessions on its courts.",
                        code: Response::HTTP_FORBIDDEN,
                    );
                }

                if ($status !== 'pending_approval') {
                    return $this->errorResponse(
                        message: "This session isn't waiting for approval.",
                        code: Response::HTTP_CONFLICT,
                    );
                }

                if ($action === 'reject') {
                    if ($reason === '') {
                        return $this->fieldError('reason', 'Tell the host why, so they can choose another time.');
                    }

                    $session->update(['status' => 'rejected', 'status_reason' => $reason]);
                } else {
                    $session->update(['status' => 'published', 'status_reason' => null]);
                }

                break;

            default:
                return $this->errorResponse(message: 'Unknown action.', code: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(data: Contract::openPlayDetail($session->fresh(), $viewer));
    }

    /**
     * The host adds a player to the list.
     */
    public function addParticipant(Request $request, OpenPlaySession $session): JsonResponse
    {
        $viewer = (int) $request->user()->id;

        if (! Contract::canManageOpenPlay($session, $viewer)) {
            return $this->errorResponse(
                message: "Only the session's organizers can add players.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        $status = Contract::openPlayStatus($session);

        if (! in_array($status, ['published', 'live'], true)) {
            return $this->errorResponse(
                message: 'Players can only be added to upcoming or live sessions.',
                code: Response::HTTP_CONFLICT,
            );
        }

        $playerId = (int) $request->input('player_id');
        $exists = DB::table('users')
            ->join('player_profiles', 'player_profiles.user_id', '=', 'users.id')
            ->where('users.id', $playerId)
            ->exists();

        if (! $exists) {
            return $this->fieldError('player_id', 'Choose a player.');
        }

        $name = (string) DB::table('users')->where('id', $playerId)->value('name');
        $already = $session->participants()
            ->where('user_id', $playerId)
            ->where('status', '!=', 'cancelled')
            ->exists();

        if ($already) {
            return $this->fieldError('player_id', "{$name} is already on this session's list.");
        }

        if ($this->activeCount($session) >= (int) $session->max_participants) {
            return $this->errorResponse(
                message: 'The session is full. Raise the capacity first.',
                code: Response::HTTP_CONFLICT,
            );
        }

        $member = $this->isMember($session, $playerId);
        $fee = $member ? (string) $session->member_fee : (string) $session->fee;

        $session->participants()->create([
            'user_id' => $playerId,
            'status' => 'registered',
            'fee_due' => $fee,
            'fee_status' => (float) $fee <= 0 ? 'free' : 'unpaid',
            'is_member' => $member,
        ]);

        return $this->successResponse(
            data: Contract::openPlayDetail($session->fresh(), $viewer),
            code: Response::HTTP_CREATED,
        );
    }

    /**
     * Record a session fee collected from a player.
     */
    public function participantPayment(Request $request, OpenPlaySession $session, int $participant): JsonResponse
    {
        $viewer = (int) $request->user()->id;

        if (! Contract::canManageOpenPlay($session, $viewer)) {
            return $this->errorResponse(
                message: "Only the session's organizers can record fees.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        $session->loadMissing(['tenant.venueProfile']);

        if ($session->organizer_type === 'venue' && Contract::club($session->tenant)['read_only']) {
            return $this->errorResponse(
                message: "{$session->tenant->name} can't take payments right now.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $row = $session->participants()->find($participant);

        if ($row === null) {
            return $this->errorResponse(
                message: 'Player not found in this session.',
                code: Response::HTTP_NOT_FOUND,
            );
        }

        $held = $this->feeHeld($row->id);
        $owed = (float) $row->fee_due - $held;

        if ($error = $this->paymentError($request, $owed, "This player doesn't owe anything.")) {
            return $error;
        }

        OpenPlayFeePayment::create([
            'open_play_session_id' => $session->id,
            'open_play_participant_id' => $row->id,
            'kind' => 'session_fee',
            'payer_id' => $row->user_id,
            'amount' => (string) $request->input('amount'),
            'method' => (string) $request->input('method'),
            'reference_number' => trim((string) $request->input('reference_number', '')) ?: null,
            'received_by' => $viewer,
            'received_at' => now(),
        ]);

        return $this->successResponse(data: Contract::openPlayDetail($session->fresh(), $viewer));
    }

    /**
     * Refund part or all of what a player has paid.
     */
    public function participantRefund(Request $request, OpenPlaySession $session, int $participant): JsonResponse
    {
        $viewer = (int) $request->user()->id;

        if (! Contract::canManageOpenPlay($session, $viewer)) {
            return $this->errorResponse(message: 'Only managers can refund fees.', code: Response::HTTP_FORBIDDEN);
        }

        $row = $session->participants()->find($participant);

        if ($row === null) {
            return $this->errorResponse(
                message: 'Player not found in this session.',
                code: Response::HTTP_NOT_FOUND,
            );
        }

        $payments = OpenPlayFeePayment::query()
            ->where('open_play_participant_id', $row->id)
            ->where('kind', 'session_fee')
            ->with('refunds')
            ->orderByDesc('id')
            ->get();

        $held = $this->feeHeld($row->id);
        $amount = (float) $request->input('amount');
        $reason = trim((string) $request->input('reason', ''));
        $errors = [];

        if (! is_numeric($request->input('amount')) || $amount <= 0) {
            $errors['amount'][] = 'Enter an amount above zero.';
        } elseif ($amount > round($held, 2)) {
            $errors['amount'][] = 'Only ₱'.number_format($held, 2).' was collected from this player.';
        }

        if ($reason === '') {
            $errors['reason'][] = "Add a reason. It's kept in the payment history.";
        }

        if ($errors !== []) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $left = $amount;

        foreach ($payments as $payment) {
            $room = (float) $payment->amount - (float) $payment->refunds->sum('amount');

            if ($room <= 0 || $left <= 0) {
                continue;
            }

            $take = min($room, $left);

            OpenPlayFeeRefund::create([
                'open_play_fee_payment_id' => $payment->id,
                'amount' => number_format($take, 2, '.', ''),
                'reason' => $reason,
                'refunded_by' => $viewer,
            ]);

            $left -= $take;
        }

        return $this->successResponse(data: Contract::openPlayDetail($session->fresh(), $viewer));
    }

    /**
     * The venue records what the host owes it for a community session.
     */
    public function courtFee(Request $request, OpenPlaySession $session): JsonResponse
    {
        $viewer = (int) $request->user()->id;

        if (! DB::table('tenant_users')->where('tenant_id', $session->tenant_id)->where('user_id', $viewer)->exists()) {
            return $this->errorResponse(
                message: "Only the venue's staff can record court fees.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        if ($session->organizer_type === 'venue') {
            return $this->errorResponse(
                message: "The venue's own sessions have no court fee.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $paid = (float) OpenPlayFeePayment::query()
            ->where('open_play_session_id', $session->id)
            ->where('kind', 'court_fee')
            ->with('refunds')
            ->get()
            ->sum(fn ($payment) => (float) $payment->amount - (float) $payment->refunds->sum('amount'));

        $owed = (float) $session->court_fee - $paid;

        if ($error = $this->paymentError($request, $owed, 'The court fee is already paid.')) {
            return $error;
        }

        OpenPlayFeePayment::create([
            'open_play_session_id' => $session->id,
            'open_play_participant_id' => null,
            'kind' => 'court_fee',
            'payer_id' => $session->host_id,
            'amount' => (string) $request->input('amount'),
            'method' => (string) $request->input('method'),
            'reference_number' => trim((string) $request->input('reference_number', '')) ?: null,
            'received_by' => $viewer,
            'received_at' => now(),
        ]);

        return $this->successResponse(data: Contract::openPlayDetail($session->fresh(), $viewer));
    }

    private function feeHeld(int $participantId): float
    {
        $payments = OpenPlayFeePayment::query()
            ->where('open_play_participant_id', $participantId)
            ->where('kind', 'session_fee')
            ->with('refunds')
            ->get();

        return (float) $payments->sum('amount')
            - (float) $payments->sum(fn ($payment) => (float) $payment->refunds->sum('amount'));
    }

    private function paymentError(Request $request, float $owed, string $nothingOwed): ?JsonResponse
    {
        if ($owed <= 0.004) {
            return $this->errorResponse(message: $nothingOwed, code: Response::HTTP_CONFLICT);
        }

        $errors = [];
        $amount = $request->input('amount');

        if (! is_numeric($amount) || (float) $amount <= 0) {
            $errors['amount'][] = 'Enter the amount you collected.';
        } elseif ((float) $amount > round($owed, 2)) {
            $errors['amount'][] = 'Only ₱'.number_format($owed, 2).' is owed.';
        }

        if (! in_array((string) $request->input('method'), ['cash', 'gcash_at_venue', 'maya_at_venue', 'card_at_venue', 'other'], true)) {
            $errors['method'][] = 'Choose how they paid.';
        }

        if ($errors !== []) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return null;
    }

    private function courtFeeFor(string $type, $courts, CarbonImmutable $starts, CarbonImmutable $ends): string
    {
        if ($type !== 'community') {
            return '0.00';
        }

        $minutes = (int) round(($ends->getTimestamp() - $starts->getTimestamp()) / 60);
        $total = 0.0;

        foreach ($courts as $court) {
            [, $lineTotal] = PlayerBookingView::lines($court, $starts, $minutes);
            $total += (float) $lineTotal;
        }

        return number_format($total, 2, '.', '');
    }

    /**
     * Check-in, undo check-in, no-show and remove.
     */
    public function participantAction(Request $request, OpenPlaySession $session, int $participant, string $action): JsonResponse
    {
        $viewer = (int) $request->user()->id;

        if (! Contract::canManageOpenPlay($session, $viewer)) {
            return $this->errorResponse(
                message: "Only the session's organizers can manage players.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        $row = $session->participants()->find($participant);

        if ($row === null) {
            return $this->errorResponse(
                message: 'Player not found in this session.',
                code: Response::HTTP_NOT_FOUND,
            );
        }

        $status = Contract::openPlayStatus($session);

        switch ($action) {
            case 'check-in':
                if (! in_array($status, ['published', 'live'], true)) {
                    return $this->errorResponse(
                        message: 'Check-in is only open for upcoming or live sessions.',
                        code: Response::HTTP_CONFLICT,
                    );
                }

                if ($session->starts_at->getTimestamp() - now()->getTimestamp() > 2 * 3600) {
                    return $this->errorResponse(
                        message: 'Check-in opens 2 hours before the start.',
                        code: Response::HTTP_CONFLICT,
                    );
                }

                if ($row->status !== 'registered') {
                    return $this->errorResponse(
                        message: $row->status === 'checked_in' ? 'Already checked in.' : 'Only registered players can check in.',
                        code: Response::HTTP_CONFLICT,
                    );
                }

                $row->update([
                    'status' => 'checked_in',
                    'checked_in_at' => now(),
                    'queue_state' => 'waiting',
                    'queue_position' => (int) $session->participants()
                        ->where('queue_state', 'waiting')
                        ->max('queue_position') + 1,
                    'queue_since' => now(),
                ]);
                $session->increment('queue_version');

                break;

            case 'undo-check-in':
                if ($row->status !== 'checked_in') {
                    return $this->errorResponse(
                        message: "This player isn't checked in.",
                        code: Response::HTTP_CONFLICT,
                    );
                }

                $row->update([
                    'status' => 'registered',
                    'checked_in_at' => null,
                    'queue_state' => null,
                    'queue_position' => null,
                    'queue_since' => null,
                ]);
                $session->increment('queue_version');

                break;

            case 'no-show':
                if ($row->status !== 'registered') {
                    return $this->errorResponse(
                        message: "Only players who haven't checked in can be marked no-show.",
                        code: Response::HTTP_CONFLICT,
                    );
                }

                if (now()->lessThan($session->starts_at)) {
                    return $this->errorResponse(
                        message: 'No-shows can be marked once the session starts.',
                        code: Response::HTTP_CONFLICT,
                    );
                }

                $row->update(['status' => 'no_show']);
                $this->promoteFromWaitlist($session);

                break;

            case 'remove':
                if ($row->status === 'cancelled') {
                    return $this->errorResponse(message: 'Already removed.', code: Response::HTTP_CONFLICT);
                }

                $row->update([
                    'status' => 'cancelled',
                    'waitlist_position' => null,
                    'queue_state' => null,
                    'queue_position' => null,
                    'queue_since' => null,
                ]);
                $session->increment('queue_version');
                $this->promoteFromWaitlist($session);
                $this->renumberWaitlist($session);

                break;

            default:
                return $this->errorResponse(message: 'Unknown action.', code: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(data: Contract::openPlayDetail($session->fresh(), $viewer));
    }

    private function membership(OpenPlaySession $session, int $userId): ?object
    {
        if ($session->organizer_type !== 'community') {
            return null;
        }

        return DB::table('club_members')
            ->where('club_id', $session->organizer_id)
            ->where('user_id', $userId)
            ->first();
    }

    private function isMember(OpenPlaySession $session, int $userId): bool
    {
        if ($session->organizer_type === 'community') {
            return DB::table('club_members')
                ->where('club_id', $session->organizer_id)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->exists();
        }

        return DB::table('tenant_users')
            ->where('tenant_id', $session->tenant_id)
            ->where('user_id', $userId)
            ->exists();
    }

    private function activeCount(OpenPlaySession $session): int
    {
        return $session->participants()
            ->whereIn('status', ['registered', 'checked_in', 'completed'])
            ->count();
    }

    private function cancellationHours(OpenPlaySession $session): int
    {
        $settings = Contract::settings($session->tenant?->venueProfile);

        return (int) ($settings['open_play_cancellation_hours'] ?? 12);
    }

    private function promoteFromWaitlist(OpenPlaySession $session): void
    {
        while ($this->activeCount($session) < (int) $session->max_participants) {
            $next = $session->participants()
                ->where('status', 'waitlisted')
                ->orderBy('waitlist_position')
                ->orderBy('created_at')
                ->first();

            if ($next === null) {
                return;
            }

            $member = (bool) $next->is_member;
            $fee = $member ? (string) $session->member_fee : (string) $session->fee;

            $next->update([
                'status' => 'registered',
                'waitlist_position' => null,
                'fee_due' => $fee,
                'fee_status' => (float) $fee <= 0 ? 'free' : 'unpaid',
            ]);

            NotificationService::send(
                (int) $next->user_id,
                'clubs',
                'waitlist_promoted',
                "You're in",
                "A spot opened in {$session->title}. You're registered; pay the fee when you check in.",
                "/sessions/{$session->id}",
            );
        }
    }

    private function renumberWaitlist(OpenPlaySession $session): void
    {
        $waitlisted = $session->participants()
            ->where('status', 'waitlisted')
            ->orderBy('waitlist_position')
            ->orderBy('created_at')
            ->get();

        foreach ($waitlisted as $index => $row) {
            $row->update(['waitlist_position' => $index + 1]);
        }
    }

    private function fieldError(string $field, string $message): JsonResponse
    {
        return $this->errorResponse(
            message: 'The given data was invalid.',
            errors: [$field => [$message]],
            code: Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
