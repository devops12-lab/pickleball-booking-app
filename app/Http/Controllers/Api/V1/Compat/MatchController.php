<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Court;
use App\Models\GameMatch;
use App\Models\MatchEvent;
use App\Models\OpenPlaySession;
use App\Models\PlayerProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Compat\Contract;
use App\Support\Compat\MatchEngine;
use App\Support\Compat\MatchView;
use App\Support\Compat\NotificationService;
use App\Support\Compat\TournamentDraw;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The SPA's match contract: log a match, run the Live Scorer, confirm or
 * dispute a result, and read a player's ratings and record.
 */
class MatchController extends Controller
{
    use ApiResponse;

    public function playerIndex(Request $request): JsonResponse
    {
        MatchEngine::settleDue();
        /** @var User $user */
        $user = $request->user();
        $rank = ['in_progress' => 0, 'scheduled' => 1];

        $matches = GameMatch::query()
            ->with(['players.user', 'venue', 'court', 'session', 'scorer', 'creator'])
            ->where(function ($query) use ($user): void {
                $query->whereHas('players', fn ($players) => $players->where('user_id', $user->id))
                    ->orWhere('scorer_id', $user->id)
                    ->orWhere('created_by', $user->id);
            })
            ->get()
            ->sort(function (GameMatch $a, GameMatch $b) use ($rank): int {
                return ($rank[$a->status] ?? 2) <=> ($rank[$b->status] ?? 2)
                    ?: (($b->ended_at ?? $b->started_at ?? $b->created_at)?->timestamp ?? 0)
                        <=> (($a->ended_at ?? $a->started_at ?? $a->created_at)?->timestamp ?? 0);
            })
            ->values()
            ->map(fn (GameMatch $match): array => MatchView::summary($match))
            ->all();

        return $this->successResponse(data: $matches);
    }

    public function clubIndex(Request $request, string $club): JsonResponse
    {
        $tenant = ctype_digit($club)
            ? Tenant::query()->find((int) $club)
            : Tenant::query()->where('slug', $club)->first();

        if ($tenant === null) {
            return $this->errorResponse('Club not found.', code: Response::HTTP_NOT_FOUND);
        }

        /** @var User $user */
        $user = $request->user();
        $member = DB::table('tenant_users')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $member && ! $user->isSystemAdmin()) {
            return $this->errorResponse('Not allowed.', code: Response::HTTP_FORBIDDEN);
        }

        MatchEngine::settleDue();
        $rank = ['in_progress' => 0, 'scheduled' => 1];

        $matches = GameMatch::query()
            ->with(['players.user', 'venue', 'court', 'session', 'scorer', 'creator'])
            ->where('venue_id', $tenant->id)
            ->get()
            ->sort(function (GameMatch $a, GameMatch $b) use ($rank): int {
                return ($rank[$a->status] ?? 2) <=> ($rank[$b->status] ?? 2)
                    ?: (($b->ended_at ?? $b->started_at ?? $b->created_at)?->timestamp ?? 0)
                        <=> (($a->ended_at ?? $a->started_at ?? $a->created_at)?->timestamp ?? 0);
            })
            ->values()
            ->map(fn (GameMatch $match): array => MatchView::summary($match))
            ->all();

        return $this->successResponse(data: $matches);
    }

    public function show(Request $request, GameMatch $match): JsonResponse
    {
        MatchEngine::settleDue();

        return $this->successResponse(data: MatchView::detail($match, $this->viewer($request)));
    }

    public function events(GameMatch $match): JsonResponse
    {
        return $this->successResponse(data: MatchView::eventViews($match));
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $uid = (int) $user->id;
        $teamA = $this->ids($request->input('team_a'));
        $teamB = $this->ids($request->input('team_b'));
        $everyone = [...$teamA, ...$teamB];
        $type = (string) $request->input('match_type', '');
        $category = (string) $request->input('category', '');
        $formatInput = $request->input('format');
        $format = $this->format(is_array($formatInput) ? $formatInput : []);
        $firstServing = $request->input('first_serving');
        $firstServing = is_numeric($firstServing) ? (int) $firstServing : -1;
        $scorerId = $request->input('scorer_id');
        $scorerId = $scorerId === null || $scorerId === '' ? $uid : (int) $scorerId;
        $courtId = $request->input('court_id');
        $courtId = $courtId === null || $courtId === '' ? null : (int) $courtId;
        $sessionId = $request->input('session_id');
        $sessionId = $sessionId === null || $sessionId === '' ? null : (int) $sessionId;
        $errors = [];

        if (! in_array($type, ['singles', 'doubles'], true)) {
            $errors['match_type'] = ['Choose singles or doubles.'];
        }

        if (! in_array($category, ['casual', 'competitive'], true)) {
            $errors['category'] = ['Choose casual or competitive.'];
        }

        if ($format === null) {
            $errors['format'] = ['Choose a game format.'];
        }

        $size = $type === 'singles' ? 1 : 2;

        if (count($teamA) !== $size || count($teamB) !== $size) {
            $errors['teams'] = [
                $size === 1 ? 'Choose one player on each side.' : 'Choose two players on each side.',
            ];
        } elseif (count(array_unique($everyone)) !== count($everyone)) {
            $errors['teams'] = ['A player can only be on one side, once.'];
        } else {
            $players = PlayerProfile::query()->whereIn('user_id', $everyone)->count();
            $users = User::query()->whereIn('id', $everyone)->count();

            if ($players !== count($everyone) || $users !== count($everyone)) {
                $errors['teams'] = ['Choose players with a CourtFlex player profile.'];
            }
        }

        if ($firstServing !== 0 && $firstServing !== 1) {
            $errors['first_serving'] = ['Choose who serves first.'];
        }

        $scorer = User::query()->find($scorerId);
        if ($scorer === null) {
            $errors['scorer_id'] = ['Choose who keeps score.'];
        }

        $court = $courtId === null ? null : Court::query()->find($courtId);
        if ($courtId !== null && $court === null) {
            $errors['court_id'] = ["That court doesn't exist."];
        }

        $session = $sessionId === null
            ? null
            : OpenPlaySession::query()->with(['courts'])->find($sessionId);
        if ($sessionId !== null && $session === null) {
            $errors['session_id'] = ["That open play session doesn't exist."];
        }

        if ($session !== null && ! Contract::canManageOpenPlay($session, $uid) && ! $user->isSystemAdmin()) {
            $errors['session_id'] = ["Only the session's host and the venue's staff can start matches for it."];
        }

        if ($session !== null && $court !== null) {
            $allowed = $session->courts->contains(fn (Court $row): bool => (int) $row->id === (int) $court->id)
                || (int) $session->court_id === (int) $court->id;

            if (! $allowed) {
                $errors['court_id'] = ["Choose one of the session's courts."];
            }
        }

        if ($court !== null && $session === null
            && ! $user->isSystemAdmin()
            && ! \App\Support\Compat\CompetitionView::canManageVenue((int) $court->tenant_id, $uid)) {
            $errors['court_id'] = ["Only the venue's staff can put a match on its court."];
        }

        if ($court === null && $session === null
            && ! $user->isSystemAdmin()
            && ! in_array($uid, $everyone, true)) {
            $errors['teams'] = ['Add yourself to one of the sides, or ask an organizer to set up the match.'];
        }

        if ($errors !== []) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $match = MatchEngine::create([
            'match_type' => $type,
            'category' => $category,
            'format' => $format,
            'team_0' => $teamA,
            'team_1' => $teamB,
            'first_serving' => $firstServing,
            'scorer_id' => $scorerId,
            'court_id' => $court?->id ?? $session?->court_id,
            'session_id' => $session?->id,
            'venue_id' => $court?->tenant_id ?? $session?->tenant_id,
        ], $user);

        return $this->successResponse(
            data: MatchView::detail($match, $uid),
            message: 'Match created.',
            code: Response::HTTP_CREATED,
        );
    }

    public function start(Request $request, GameMatch $match): JsonResponse
    {
        if (! $this->canScore($match, $this->viewer($request))) {
            return $this->errorResponse('Only the scorer or an organizer can start this match.', code: Response::HTTP_FORBIDDEN);
        }

        if ($match->status !== 'scheduled') {
            return $this->errorResponse('This match has already started.', code: Response::HTTP_CONFLICT);
        }

        $match->started_at = now();
        $match->status = 'in_progress';
        $match->save();

        return $this->detailResponse($match, $request);
    }

    public function score(Request $request, GameMatch $match): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $uuid = trim((string) $request->input('event_uuid', ''));
        $winner = $request->input('winner');
        $winner = is_numeric($winner) ? (int) $winner : -1;

        if ($uuid === '') {
            return $this->validationError(['event_uuid' => ['Send an id for this point.']]);
        }

        if (MatchEvent::query()->where('match_id', $match->id)->where('event_uuid', $uuid)->exists()) {
            return $this->detailResponse($match, $request);
        }

        if (! $this->canScore($match, (int) $user->id)) {
            return $this->errorResponse('Only the scorer or an organizer can score this match.', code: Response::HTTP_FORBIDDEN);
        }

        if ($match->status !== 'in_progress') {
            return $this->errorResponse("This match isn't being played.", code: Response::HTTP_CONFLICT);
        }

        if ($winner !== 0 && $winner !== 1) {
            return $this->validationError(['winner' => ['Choose which side won the rally.']]);
        }

        if (MatchEngine::state($match)['winner'] !== null) {
            return $this->errorResponse('The match is over. Submit the result, or undo the last point.', code: Response::HTTP_CONFLICT);
        }

        MatchEngine::recordEvent($match, $uuid, 'rally', $winner, (int) $user->id);

        return $this->detailResponse($match, $request);
    }

    public function undo(Request $request, GameMatch $match): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $uuid = trim((string) $request->input('event_uuid', ''));

        if ($uuid === '') {
            return $this->validationError(['event_uuid' => ['Send an id for this undo.']]);
        }

        if (MatchEvent::query()->where('match_id', $match->id)->where('event_uuid', $uuid)->exists()) {
            return $this->detailResponse($match, $request);
        }

        if (! $this->canScore($match, (int) $user->id)) {
            return $this->errorResponse('Only the scorer or an organizer can score this match.', code: Response::HTTP_FORBIDDEN);
        }

        if ($match->status !== 'in_progress') {
            return $this->errorResponse("This match isn't being played.", code: Response::HTTP_CONFLICT);
        }

        if (MatchEngine::state($match)['rallies'] === 0) {
            return $this->errorResponse("There's no point to undo.", code: Response::HTTP_CONFLICT);
        }

        MatchEngine::recordEvent($match, $uuid, 'undo', null, (int) $user->id);

        return $this->detailResponse($match, $request);
    }

    public function submit(Request $request, GameMatch $match): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->canScore($match, (int) $user->id)) {
            return $this->errorResponse('Only the scorer or an organizer can submit the result.', code: Response::HTTP_FORBIDDEN);
        }

        if ($match->status !== 'in_progress') {
            return $this->errorResponse("This match isn't being played.", code: Response::HTTP_CONFLICT);
        }

        if (MatchEngine::state($match)['winner'] === null) {
            return $this->errorResponse("The match isn't over yet.", code: Response::HTTP_CONFLICT);
        }

        if (! MatchEngine::submit($match, (int) $user->id)) {
            return $this->errorResponse("The match isn't over yet.", code: Response::HTTP_CONFLICT);
        }

        return $this->detailResponse($match, $request);
    }

    public function confirm(Request $request, GameMatch $match): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($match->status !== 'awaiting_confirmation') {
            return $this->errorResponse("This result isn't waiting for confirmation.", code: Response::HTTP_CONFLICT);
        }

        $side = MatchView::awaitingSide($match);

        if ($side === null || ! MatchView::onSide($match, (int) $user->id, $side)) {
            return $this->errorResponse('Only a player on the other side confirms this result.', code: Response::HTTP_FORBIDDEN);
        }

        if (! MatchEngine::confirm($match, (int) $user->id)) {
            return $this->errorResponse("This result isn't waiting for confirmation.", code: Response::HTTP_CONFLICT);
        }

        return $this->detailResponse($match, $request);
    }

    public function dispute(Request $request, GameMatch $match): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! MatchView::onTeam($match, (int) $user->id)) {
            return $this->errorResponse('Only the players in the match can dispute it.', code: Response::HTTP_FORBIDDEN);
        }

        if ($match->status !== 'awaiting_confirmation') {
            return $this->errorResponse(
                $match->status === 'disputed'
                    ? 'This result is already disputed.'
                    : "This result can't be disputed now.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $reason = trim((string) $request->input('reason', ''));

        if (mb_strlen($reason) < 10) {
            return $this->validationError([
                'reason' => ["Say what's wrong in a sentence (at least 10 characters)."],
            ]);
        }

        $match->status = 'disputed';
        $match->dispute_by = $user->id;
        $match->dispute_reason = $reason;
        $match->dispute_at = now();
        $match->save();

        $match->loadMissing(['players.user', 'creator']);
        $recipients = $match->players->pluck('user_id')->push($match->created_by)->unique()->filter();

        foreach ($recipients as $recipient) {
            if ((int) $recipient === (int) $user->id) {
                continue;
            }

            NotificationService::send(
                (int) $recipient,
                'matches',
                'result_disputed',
                'A result was disputed',
                "{$user->name} disputed it: \"{$reason}\" It doesn't count until it's settled.",
                "/matches/{$match->id}",
            );
        }

        return $this->detailResponse($match, $request);
    }

    public function verify(Request $request, GameMatch $match): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! MatchView::canVerify($match, (int) $user->id)) {
            return $this->errorResponse(
                "Only an organizer who isn't playing, or the Super Admin, can make this result official.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        if (! in_array($match->status, ['awaiting_confirmation', 'disputed'], true)) {
            return $this->errorResponse("This result isn't waiting for a decision.", code: Response::HTTP_CONFLICT);
        }

        MatchEngine::complete($match, (int) $user->id);

        return $this->detailResponse($match, $request);
    }

    public function changeScorer(Request $request, GameMatch $match): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! MatchEngine::canManage($match, (int) $user->id)) {
            return $this->errorResponse("Only the match's organizer can change the scorer.", code: Response::HTTP_FORBIDDEN);
        }

        if (! in_array($match->status, ['scheduled', 'in_progress'], true)) {
            return $this->errorResponse('The scorer can only change before the result is in.', code: Response::HTTP_CONFLICT);
        }

        $scorerId = $request->input('scorer_id');
        $scorerId = is_numeric($scorerId) ? (int) $scorerId : 0;

        if ($scorerId === 0 || User::query()->find($scorerId) === null) {
            return $this->validationError(['scorer_id' => ['Choose who keeps score.']]);
        }

        $match->scorer_id = $scorerId;
        $match->save();

        return $this->detailResponse($match, $request);
    }

    public function void(Request $request, GameMatch $match): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! MatchEngine::canManage($match, (int) $user->id)) {
            return $this->errorResponse("Only the match's organizer can void it.", code: Response::HTTP_FORBIDDEN);
        }

        $reason = trim((string) $request->input('reason', ''));

        if (mb_strlen($reason) < 10) {
            return $this->validationError([
                'reason' => ['Say why in a sentence (at least 10 characters).'],
            ]);
        }

        if (in_array($match->status, ['completed', 'voided'], true)) {
            return $this->errorResponse(
                $match->status === 'voided' ? 'This match is already void.' : "A completed match can't be voided.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $match->status = 'voided';
        $match->void_reason = $reason;
        $match->ended_at ??= now();
        $match->save();

        if ($match->tournament_id !== null) {
            TournamentDraw::replaceVoided($match);
        }

        return $this->detailResponse($match, $request);
    }

    public function stats(Request $request, string $player): JsonResponse
    {
        MatchEngine::settleDue();
        /** @var User $user */
        $user = $request->user();
        $playerId = $player === 'me' ? (int) $user->id : (int) $player;
        $stats = MatchEngine::stats($playerId, (int) $user->id);

        if ($stats === null) {
            return $this->errorResponse("This player doesn't exist.", code: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(data: $stats);
    }

    private function detailResponse(GameMatch $match, Request $request): JsonResponse
    {
        $match->refresh();

        return $this->successResponse(
            data: MatchView::detail($match, $this->viewer($request)),
            message: 'Match updated.',
        );
    }

    private function canScore(GameMatch $match, ?int $viewerId): bool
    {
        return $viewerId !== null
            && ((int) $match->scorer_id === $viewerId || MatchEngine::canManage($match, $viewerId));
    }

    private function viewer(Request $request): ?int
    {
        $user = $request->user() ?? Auth::guard('web')->user();

        return $user === null ? null : (int) $user->id;
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $id) {
            if (is_numeric($id)) {
                $out[] = (int) $id;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{target: int, win_by: int, best_of: int, scoring: string}|null
     */
    private function format(array $input): ?array
    {
        $target = (int) ($input['target'] ?? 0);
        $winBy = (int) ($input['win_by'] ?? 0);
        $bestOf = (int) ($input['best_of'] ?? 0);
        $scoring = (string) ($input['scoring'] ?? '');

        if (! in_array($target, [11, 15, 21], true)
            || ! in_array($winBy, [1, 2], true)
            || ! in_array($bestOf, [1, 3], true)
            || ! in_array($scoring, ['side_out', 'rally'], true)) {
            return null;
        }

        return [
            'target' => $target,
            'win_by' => $winBy,
            'best_of' => $bestOf,
            'scoring' => $scoring,
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
