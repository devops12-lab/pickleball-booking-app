<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Club;
use App\Models\Court;
use App\Models\GameMatch;
use App\Models\PlayerProfile;
use App\Models\Tenant;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Compat\CompetitionView;
use App\Support\Compat\Contract;
use App\Support\Compat\NotificationService;
use App\Support\Compat\TournamentDraw;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TournamentController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $status = trim((string) $request->query('status', ''));

        if ($status !== '' && ! in_array($status, CompetitionView::STATUSES, true)) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: ['status' => ['Choose a tournament status.']],
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $viewer = $this->viewer($request);
        $rank = ['in_progress' => 0, 'registration' => 1, 'completed' => 2, 'cancelled' => 3];

        $tournaments = Tournament::query()
            ->with(['tenant.venueProfile', 'venue.venueProfile', 'participants.user', 'participants.partner'])
            ->get()
            ->filter(fn (Tournament $tournament): bool => $status === ''
                ? CompetitionView::status($tournament->status) !== 'cancelled'
                : CompetitionView::status($tournament->status) === $status)
            ->sort(fn (Tournament $a, Tournament $b): int => ($rank[CompetitionView::status($a->status)] ?? 9)
                <=> ($rank[CompetitionView::status($b->status)] ?? 9)
                ?: (($a->starts_at?->timestamp ?? 0) <=> ($b->starts_at?->timestamp ?? 0)))
            ->values()
            ->map(fn (Tournament $tournament): array => CompetitionView::summary($tournament, $viewer))
            ->all();

        return $this->successResponse(data: $tournaments);
    }

    public function hostingOptions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponse(data: CompetitionView::hostOptions($user));
    }

    public function clubIndex(Request $request, string $club): JsonResponse
    {
        $tenant = ctype_digit($club)
            ? Tenant::query()->find((int) $club)
            : Tenant::query()->where('slug', $club)->first();

        if ($tenant === null) {
            return $this->errorResponse(message: 'Club not found.', code: Response::HTTP_NOT_FOUND);
        }

        /** @var User $user */
        $user = $request->user();
        $member = DB::table('tenant_users')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $member && ! $user->isSystemAdmin()) {
            return $this->errorResponse(message: 'Not allowed.', code: Response::HTTP_FORBIDDEN);
        }

        $tournaments = Tournament::query()
            ->with(['tenant.venueProfile', 'venue.venueProfile', 'participants.user', 'participants.partner'])
            ->where(function ($query) use ($tenant): void {
                $query->where('tenant_id', $tenant->id)
                    ->orWhere('venue_id', $tenant->id)
                    ->orWhere(function ($host) use ($tenant): void {
                        $host->where('host_type', 'venue')->where('host_id', $tenant->id);
                    });
            })
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Tournament $tournament): array => CompetitionView::summary($tournament, (int) $user->id))
            ->all();

        return $this->successResponse(data: $tournaments);
    }

    public function show(Request $request, Tournament $tournament): JsonResponse
    {
        return $this->successResponse(data: CompetitionView::detail($tournament, $this->viewer($request)));
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $host = $request->input('host');
        $host = is_array($host) ? $host : [];
        $hostType = (string) ($host['type'] ?? '');
        $hostId = (string) ($host['id'] ?? '');
        $hostOption = collect(CompetitionView::hostOptions($user))
            ->first(fn (array $option): bool => $option['type'] === $hostType && $option['id'] === $hostId);

        $name = trim((string) $request->input('name', ''));
        $description = trim((string) $request->input('description', ''));
        $matchType = (string) $request->input('match_type', '');
        $format = (string) $request->input('format', '');
        $game = $request->input('game');
        $game = is_array($game) ? $game : [];
        $venueId = (string) $request->input('venue_id', '');
        $courtIds = $request->input('court_ids', []);
        $courtIds = is_array($courtIds) ? array_values(array_map('strval', $courtIds)) : [];
        $pools = $format === 'pool_knockout' ? (int) $request->input('pools', 2) : null;
        $capacity = filter_var($request->input('capacity'), FILTER_VALIDATE_INT);
        $rawFee = $request->input('entry_fee', 0);
        $fee = is_numeric($rawFee) ? (float) $rawFee : null;
        $errors = [];

        if ($hostOption === null) {
            $errors['host'][] = 'Choose a venue or club you run.';
        }

        if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
            $errors['name'][] = 'Use 3 to 80 characters.';
        }

        if (! in_array($matchType, ['singles', 'doubles'], true)) {
            $errors['match_type'][] = 'Choose singles or doubles.';
        }

        if (! in_array($format, CompetitionView::FORMATS, true)) {
            $errors['format'][] = 'Choose a format.';
        }

        $target = (int) ($game['target'] ?? 0);
        $bestOf = (int) ($game['best_of'] ?? 0);
        $scoring = (string) ($game['scoring'] ?? '');

        if (! in_array($target, [11, 15, 21], true)
            || ! in_array($bestOf, [1, 3], true)
            || ! in_array($scoring, ['side_out', 'rally'], true)) {
            $errors['game'][] = 'Choose the game rules.';
        }

        $venue = $venueId === '' ? null : Tenant::query()->find((int) $venueId);

        if ($venue === null) {
            $errors['venue_id'][] = 'Choose the venue.';
        } else {
            $club = Contract::club($venue);

            if ($club['status'] !== 'active' || $club['read_only']) {
                $errors['venue_id'][] = 'Choose an active venue.';
            } elseif ($hostType === 'venue' && (string) $venue->id !== $hostId) {
                $errors['venue_id'][] = 'A venue hosts at its own courts.';
            }
        }

        if ($venue !== null) {
            $validCourts = Court::query()
                ->where('tenant_id', $venue->id)
                ->whereIn('id', $courtIds)
                ->count();

            if ($courtIds === [] || $validCourts !== count($courtIds)) {
                $errors['court_ids'][] = "Choose at least one of the venue's courts.";
            }
        }

        $starts = null;
        $closes = null;

        try {
            $starts = CarbonImmutable::parse((string) $request->input('starts_at', ''))->utc();
        } catch (Throwable) {
            $errors['starts_at'][] = 'Choose a start in the future.';
        }

        try {
            $closes = CarbonImmutable::parse((string) $request->input('registration_closes_at', ''))->utc();
        } catch (Throwable) {
            $errors['registration_closes_at'][] = 'Choose when registration closes, in the future.';
        }

        if ($starts !== null && $starts->lessThan(CarbonImmutable::now())) {
            $errors['starts_at'][] = 'Choose a start in the future.';
        }

        if ($closes !== null && $closes->lessThan(CarbonImmutable::now())) {
            $errors['registration_closes_at'][] = 'Choose when registration closes, in the future.';
        } elseif ($closes !== null && $starts !== null && $closes->greaterThan($starts)) {
            $errors['registration_closes_at'][] = 'Close registration before the tournament starts.';
        }

        if ($capacity === false || $capacity === null || $capacity < 4 || $capacity > 32) {
            $errors['capacity'][] = 'Choose 4 to 32 entries.';
        }

        if ($fee === null || $fee < 0 || $fee > 5000) {
            $errors['entry_fee'][] = 'Enter ₱0 to ₱5,000.';
        }

        if ($pools !== null && ($pools < 2 || $pools > 4)) {
            $errors['pools'][] = 'Choose 2 to 4 pools.';
        } elseif ($pools !== null && is_int($capacity) && $capacity < $pools * 3) {
            $errors['capacity'][] = "{$pools} pools need at least ".($pools * 3).' entries.';
        }

        if ($errors !== []) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /** @var Tenant $venue */
        /** @var CarbonImmutable $starts */
        /** @var CarbonImmutable $closes */
        $slug = Str::slug($name);
        $base = $slug;
        $suffix = 2;

        while (Tournament::query()->where('tenant_id', $venue->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        $tournament = Tournament::query()->create([
            'tenant_id' => $venue->id,
            'venue_id' => $venue->id,
            'organizer_id' => $user->id,
            'host_type' => $hostType,
            'host_id' => (int) $hostId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'format' => $format,
            'match_type' => $matchType,
            'court_ids' => $courtIds,
            'game' => CompetitionView::game($game),
            'starts_at' => $starts,
            'ends_at' => $starts->addHours(8),
            'registration_deadline' => $closes,
            'entry_fee' => $fee,
            'max_teams' => $capacity,
            'capacity' => $capacity,
            'pools' => $pools,
            'status' => 'registration',
        ]);

        return $this->successResponse(
            data: CompetitionView::detail($tournament, (int) $user->id),
            message: 'Tournament created.',
            code: Response::HTTP_CREATED,
        );
    }

    public function register(Request $request, Tournament $tournament): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $block = CompetitionView::registrationBlock($tournament, (int) $user->id);

        if ($block !== null) {
            return $this->errorResponse(message: $block, code: Response::HTTP_CONFLICT);
        }

        $partnerId = null;

        if (CompetitionView::matchType($tournament->match_type) === 'doubles') {
            $partnerId = $request->input('partner_id');
            $partnerId = $partnerId === null || $partnerId === '' ? null : (int) $partnerId;
            $partner = $partnerId === null ? null : User::query()->find($partnerId);
            $partnerHasProfile = $partnerId !== null
                && PlayerProfile::query()->where('user_id', $partnerId)->exists();
            $partnerAlreadyIn = $partnerId !== null && CompetitionView::activeEntries($tournament)
                ->contains(fn (TournamentParticipant $entry): bool => (int) $entry->user_id === $partnerId
                    || (int) $entry->partner_id === $partnerId);

            if ($partner === null || ! $partnerHasProfile || $partnerId === (int) $user->id) {
                return $this->errorResponse(
                    message: 'The given data was invalid.',
                    errors: ['partner_id' => ['Choose your partner.']],
                    code: Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            if ($partnerAlreadyIn) {
                return $this->errorResponse(
                    message: 'The given data was invalid.',
                    errors: ['partner_id' => ["{$partner->name} is already in this tournament."]],
                    code: Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        $entry = TournamentParticipant::query()->create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'partner_id' => $partnerId,
            'status' => 'registered',
        ]);

        $tournament->load(['tenant.venueProfile', 'venue.venueProfile', 'participants.user', 'participants.partner', 'matches']);

        return $this->successResponse(
            data: CompetitionView::detail($tournament, (int) $user->id),
            message: 'Registered.',
            code: Response::HTTP_CREATED,
        );
    }

    public function withdraw(Request $request, Tournament $tournament): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $entry = CompetitionView::myEntry($tournament, (int) $user->id);

        if ($entry === null) {
            return $this->errorResponse(message: "You're not registered.", code: Response::HTTP_CONFLICT);
        }

        if (CompetitionView::status($tournament->status) !== 'registration') {
            return $this->errorResponse(
                message: "The draw is made. Ask the organizer if you can't play.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $entry->update(['status' => 'withdrawn']);
        $tournament->load(['tenant.venueProfile', 'venue.venueProfile', 'participants.user', 'participants.partner', 'matches']);

        return $this->successResponse(
            data: CompetitionView::detail($tournament, (int) $user->id),
            message: 'Withdrawn.',
        );
    }

    public function start(Request $request, Tournament $tournament): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! CompetitionView::canManage($tournament, (int) $user->id)) {
            return $this->errorResponse(
                message: "Only the tournament's organizers can start it.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        if (CompetitionView::status($tournament->status) !== 'registration') {
            return $this->errorResponse(
                message: 'This tournament has already started.',
                code: Response::HTTP_CONFLICT,
            );
        }

        $entries = CompetitionView::activeEntries($tournament)
            ->sort(function (TournamentParticipant $a, TournamentParticipant $b) use ($tournament): int {
                return CompetitionView::entryRating($b, CompetitionView::matchType($tournament->match_type))
                    <=> CompetitionView::entryRating($a, CompetitionView::matchType($tournament->match_type))
                    ?: (($a->created_at?->timestamp ?? 0) <=> ($b->created_at?->timestamp ?? 0));
            })
            ->values();

        $format = CompetitionView::format($tournament->format);
        $need = $format === 'single_elimination'
            ? 2
            : ($format === 'round_robin' ? 3 : max(2, (int) ($tournament->pools ?? 2)) * 2);

        if ($entries->count() < $need) {
            return $this->errorResponse(
                message: "It needs at least {$need} entries to start; it has {$entries->count()}.",
                code: Response::HTTP_CONFLICT,
            );
        }

        GameMatch::query()->where('tournament_id', $tournament->id)->delete();
        TournamentMatch::query()->where('tournament_id', $tournament->id)->delete();

        foreach ($entries as $index => $entry) {
            $entry->update([
                'seed' => $index + 1,
                'rating' => CompetitionView::entryRating($entry, CompetitionView::matchType($tournament->match_type)),
            ]);
        }

        $tournament->update(['status' => 'in_progress']);
        TournamentDraw::build($tournament, $entries);

        $total = $entries->count();

        foreach ($entries as $entry) {
            foreach (array_filter([$entry->user_id, $entry->partner_id]) as $playerId) {
                NotificationService::send(
                    (int) $playerId,
                    'tournaments',
                    'tournament_draw',
                    "{$tournament->name}: the draw is made",
                    "You're seed {$entry->seed} of {$total}. Your first match is on the tournament page.",
                    "/tournaments/{$tournament->slug}",
                );
            }
        }

        $tournament->load(['tenant.venueProfile', 'venue.venueProfile', 'participants.user', 'participants.partner', 'matches']);

        return $this->successResponse(
            data: CompetitionView::detail($tournament, (int) $user->id),
            message: 'The draw is made.',
        );
    }

    public function cancel(Request $request, Tournament $tournament): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! CompetitionView::canManage($tournament, (int) $user->id)) {
            return $this->errorResponse(
                message: "Only the tournament's organizers can cancel it.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        if (in_array(CompetitionView::status($tournament->status), ['completed', 'cancelled'], true)) {
            return $this->errorResponse(
                message: 'This tournament is already over.',
                code: Response::HTTP_CONFLICT,
            );
        }

        $reason = trim((string) $request->input('reason', ''));

        if (mb_strlen($reason) < 10) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: ['reason' => ['Say why in a sentence (at least 10 characters).']],
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $tournament->update(['status' => 'cancelled', 'cancel_reason' => $reason]);
        TournamentMatch::query()
            ->where('tournament_id', $tournament->id)
            ->whereIn('status', ['scheduled', 'in_progress', 'bye'])
            ->update(['status' => 'voided']);
        GameMatch::query()
            ->where('tournament_id', $tournament->id)
            ->whereIn('status', ['scheduled', 'in_progress', 'awaiting_confirmation', 'disputed'])
            ->update(['status' => 'voided', 'void_reason' => $reason, 'ended_at' => now()]);
        $tournament->load(['tenant.venueProfile', 'venue.venueProfile', 'participants.user', 'participants.partner', 'matches']);

        return $this->successResponse(
            data: CompetitionView::detail($tournament, (int) $user->id),
            message: 'Tournament cancelled.',
        );
    }

    private function viewer(Request $request): ?int
    {
        $user = $request->user() ?? Auth::guard('web')->user();

        return $user === null ? null : (int) $user->id;
    }
}
