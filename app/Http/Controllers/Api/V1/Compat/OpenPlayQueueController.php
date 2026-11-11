<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\OpenPlayParticipant;
use App\Models\OpenPlayQueueGame;
use App\Models\OpenPlaySession;
use App\Support\Compat\Contract;
use App\Support\Compat\QueueView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The live queue board (handlers-open-play.ts). The contract has no scoring
 * link, so games carry `match: null`; starting, finishing, reordering and
 * resting all persist and bump the session's queue version.
 */
class OpenPlayQueueController extends Controller
{
    use ApiResponse;

    /**
     * `GET /open-play/:id/queue`
     */
    public function show(Request $request, OpenPlaySession $session): JsonResponse
    {
        $viewer = (int) $request->user()->id;

        if (! $this->canSee($session, $viewer)) {
            return $this->errorResponse(message: 'Session not found.', code: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(data: QueueView::state($session->fresh()));
    }

    /**
     * `POST /open-play/:id/queue/:command`
     */
    public function command(Request $request, OpenPlaySession $session, string $command): JsonResponse
    {
        $viewer = (int) $request->user()->id;

        if (! Contract::canManageOpenPlay($session, $viewer)) {
            return $this->errorResponse(
                message: "Only the session's organizers can run the queue.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        if (! $this->queueOpen($session)) {
            return $this->errorResponse(
                message: 'The queue opens 30 minutes before the session starts.',
                code: Response::HTTP_CONFLICT,
            );
        }

        if (! in_array($command, ['fill', 'finish', 'move', 'rest'], true)) {
            return $this->errorResponse(message: 'Unknown command.', code: Response::HTTP_NOT_FOUND);
        }

        $result = DB::transaction(function () use ($request, $session, $command): ?JsonResponse {
            return match ($command) {
                'fill' => $this->fill($request, $session),
                'finish' => $this->finish($request, $session),
                'move' => $this->move($request, $session),
                'rest' => $this->rest($request, $session),
            };
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return $this->successResponse(data: QueueView::state($session->fresh()));
    }

    private function fill(Request $request, OpenPlaySession $session): ?JsonResponse
    {
        $session->loadMissing(['courts', 'court', 'participants']);

        $courtId = $request->input('court_id');
        $courtIds = $session->courts->pluck('id')->map(fn ($id): int => (int) $id);

        if ($session->court !== null) {
            $courtIds->push((int) $session->court->id);
        }

        if (! is_numeric($courtId) || ! $courtIds->contains((int) $courtId)) {
            return $this->invalid(['court_id' => ["Choose one of this session's courts."]]);
        }

        $busy = $session->queueGames()
            ->where('court_id', (int) $courtId)
            ->whereNull('ended_at')
            ->exists();

        if ($busy) {
            return $this->errorResponse(
                message: 'That court already has a game. Finish it first.',
                code: Response::HTTP_CONFLICT,
            );
        }

        $waiting = $session->participants
            ->where('queue_state', 'waiting')
            ->whereIn('status', ['checked_in', 'completed'])
            ->sortBy(fn (OpenPlayParticipant $p): array => [$p->queue_position ?? PHP_INT_MAX, $p->id])
            ->values();

        $picked = $request->input('participant_ids');

        if (is_array($picked) && $picked !== []) {
            $ids = array_values(array_unique(array_map('intval', $picked)));

            if (count($ids) !== 4) {
                return $this->invalid(['participant_ids' => ['Pick exactly 4 players for doubles.']]);
            }

            $free = $session->participants
                ->filter(fn (OpenPlayParticipant $p): bool => $p->status === 'checked_in'
                    && in_array($p->queue_state, ['waiting', 'resting'], true))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if (array_diff($ids, $free) !== []) {
                return $this->errorResponse(
                    message: 'Some of those players are already on a court. Refresh and pick again.',
                    code: Response::HTTP_CONFLICT,
                );
            }
        } else {
            if ($waiting->count() < 4) {
                $count = $waiting->count();

                return $this->errorResponse(
                    message: 'Need 4 players waiting to start a game. '.$count.' '.($count === 1 ? 'is' : 'are').' waiting.',
                    code: Response::HTTP_CONFLICT,
                );
            }

            $ids = $waiting->take(4)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        $this->startGame($session, (int) $courtId, $ids);

        return null;
    }

    private function finish(Request $request, OpenPlaySession $session): ?JsonResponse
    {
        $session->loadMissing('participants');

        $gameId = $request->input('game_id');
        $game = is_numeric($gameId)
            ? $session->queueGames()->whereNull('ended_at')->find((int) $gameId)
            : null;

        if ($game === null) {
            return $this->errorResponse(
                message: 'That game already ended. The board has been refreshed.',
                code: Response::HTTP_CONFLICT,
            );
        }

        $winner = $request->input('winner');
        $winner = in_array($winner, ['a', 'b'], true) ? $winner : null;
        $game->update(['ended_at' => now(), 'winner' => $winner]);

        $players = array_merge($game->team_a ?? [], $game->team_b ?? []);
        $position = QueueView::nextPosition($session);

        foreach ($players as $participantId) {
            $participant = $session->participants->firstWhere('id', (int) $participantId);

            if ($participant === null) {
                continue;
            }

            $participant->update([
                'games_played' => (int) $participant->games_played + 1,
                'queue_state' => 'waiting',
                'queue_position' => $position++,
                'queue_since' => now(),
            ]);
        }

        QueueView::touch($session);

        $session->refresh()->load('participants');
        $waiting = $session->participants
            ->where('queue_state', 'waiting')
            ->whereIn('status', ['checked_in', 'completed'])
            ->sortBy(fn (OpenPlayParticipant $p): array => [$p->queue_position ?? PHP_INT_MAX, $p->id])
            ->values();

        $firstFour = $waiting->take(4)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $allFinished = array_diff($firstFour, array_map('intval', $players)) === [];

        if ($waiting->count() >= 8 || ($waiting->count() >= 4 && ! $allFinished)) {
            $this->startGame($session, (int) $game->court_id, $firstFour);
        }

        return null;
    }

    private function move(Request $request, OpenPlaySession $session): ?JsonResponse
    {
        $participantId = (int) $request->input('participant_id');
        $waiting = $session->participants()
            ->where('queue_state', 'waiting')
            ->whereIn('status', ['checked_in', 'completed'])
            ->orderBy('queue_position')
            ->orderBy('id')
            ->get()
            ->values();

        $index = $waiting->search(fn (OpenPlayParticipant $p): bool => (int) $p->id === $participantId);

        if ($index === false) {
            return $this->errorResponse(
                message: "That player isn't waiting in line.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $entry = $waiting->pull($index);
        $target = max(1, min((int) ($request->input('position') ?: 1), $waiting->count() + 1)) - 1;
        $waiting->splice($target, 0, [$entry]);

        foreach ($waiting->values() as $position => $participant) {
            $participant->update(['queue_position' => $position + 1]);
        }

        QueueView::touch($session);

        return null;
    }

    private function rest(Request $request, OpenPlaySession $session): ?JsonResponse
    {
        $session->loadMissing('participants');

        $participant = $session->participants->firstWhere('id', (int) $request->input('participant_id'));

        if ($participant === null) {
            return $this->errorResponse(message: 'Player not found in this session.', code: Response::HTTP_NOT_FOUND);
        }

        $resting = (bool) $request->input('resting', false);

        if ($resting) {
            if ($participant->queue_state !== 'waiting') {
                return $this->errorResponse(
                    message: 'Only players waiting in line can sit out.',
                    code: Response::HTTP_CONFLICT,
                );
            }

            $participant->update([
                'queue_state' => 'resting',
                'queue_position' => null,
                'queue_since' => null,
            ]);
        } else {
            if ($participant->queue_state !== 'resting') {
                return $this->errorResponse(
                    message: "That player isn't sitting out.",
                    code: Response::HTTP_CONFLICT,
                );
            }

            $participant->update([
                'queue_state' => 'waiting',
                'queue_position' => QueueView::nextPosition($session),
                'queue_since' => now(),
            ]);
        }

        QueueView::touch($session);

        return null;
    }

    /**
     * Put four players on a court: [1st + 4th] vs [2nd + 3rd].
     *
     * @param  list<int>  $ids
     */
    private function startGame(OpenPlaySession $session, int $courtId, array $ids): OpenPlayQueueGame
    {
        $game = $session->queueGames()->create([
            'court_id' => $courtId,
            'team_a' => [$ids[0], $ids[3]],
            'team_b' => [$ids[1], $ids[2]],
            'started_at' => now(),
            'ended_at' => null,
            'winner' => null,
        ]);

        OpenPlayParticipant::query()
            ->whereIn('id', $ids)
            ->update([
                'queue_state' => 'playing',
                'queue_position' => null,
                'queue_since' => null,
            ]);

        QueueView::touch($session);

        return $game;
    }

    private function queueOpen(OpenPlaySession $session): bool
    {
        $now = now();

        if (in_array($session->status, ['completed', 'cancelled', 'rejected'], true)) {
            return false;
        }

        if (in_array($session->status, ['live', 'in_progress'], true)) {
            return true;
        }

        if ($session->starts_at->lessThanOrEqualTo($now) && $session->ends_at->greaterThan($now)) {
            return true;
        }

        return $session->starts_at->greaterThan($now)
            && $session->starts_at->getTimestamp() - $now->getTimestamp() < 30 * 60;
    }

    private function canSee(OpenPlaySession $session, int $viewer): bool
    {
        if (Contract::openPlayStatus($session) === 'published') {
            return true;
        }

        if (Contract::canManageOpenPlay($session, $viewer)) {
            return true;
        }

        return $session->participants()->where('user_id', $viewer)->exists();
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function invalid(array $errors): JsonResponse
    {
        return $this->errorResponse(
            message: 'The given data was invalid.',
            errors: $errors,
            code: Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
