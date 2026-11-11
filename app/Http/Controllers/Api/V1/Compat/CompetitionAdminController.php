<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesPlatformAdmin;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\GameMatch;
use App\Support\Compat\AdminView;
use App\Support\Compat\MatchEngine;
use App\Support\Compat\MatchView;
use App\Support\Compat\NotificationService;
use App\Support\Compat\TournamentDraw;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Super Admin's competition desk: disputed results and reversing an
 * official non-tournament result with compensating rating entries.
 */
class CompetitionAdminController extends Controller
{
    use ApiResponse, AuthorizesPlatformAdmin;

    public function disputes(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $status = (string) $request->query('status', 'open');

        if (! in_array($status, ['open', 'resolved'], true)) {
            return $this->validationError(['status' => ['Choose open or resolved.']]);
        }

        $query = GameMatch::query()
            ->with([
                'players.user', 'venue', 'court', 'session', 'scorer', 'creator',
                'tournament', 'disputer', 'submitter', 'resolver',
            ]);

        if ($status === 'open') {
            $rows = $query->where('status', 'disputed')->orderBy('dispute_at')->orderBy('id')->get();
        } else {
            $rows = $query->whereNotNull('resolution_at')->orderByDesc('resolution_at')->orderByDesc('id')->get();
        }

        return $this->successResponse(data: $rows->map(fn (GameMatch $match): array => MatchView::adminDispute($match))->all());
    }

    public function resolve(Request $request, GameMatch $match): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        if ($match->status !== 'disputed') {
            return $this->errorResponse("This result isn't disputed.", code: Response::HTTP_CONFLICT);
        }

        $decision = (string) $request->input('decision', '');
        $note = trim((string) $request->input('note', ''));
        $errors = [];

        if (! in_array($decision, ['keep', 'void'], true)) {
            $errors['decision'] = ['Keep the result or void the match.'];
        }

        if (mb_strlen($note) < 10) {
            $errors['note'] = ['Explain the decision in a sentence (at least 10 characters). The players see it.'];
        }

        if ($errors !== []) {
            return $this->validationError($errors);
        }

        /** @var \App\Models\User $admin */
        $admin = $request->user();
        $match->resolution_decision = $decision;
        $match->resolution_note = $note;
        $match->resolution_by = $admin->id;
        $match->resolution_at = now();
        $match->save();

        if ($decision === 'keep') {
            MatchEngine::complete($match, (int) $admin->id, notify: false);
        } else {
            $match->status = 'voided';
            $match->void_reason = $note;
            $match->ended_at ??= now();
            $match->save();

            if ($match->tournament_id !== null) {
                TournamentDraw::replaceVoided($match);
            }
        }

        $match->refresh();
        $match->loadMissing('players.user');
        $name = $this->matchName($match);

        foreach ($match->players as $player) {
            NotificationService::send(
                (int) $player->user_id,
                'matches',
                'dispute_settled',
                $decision === 'keep' ? 'Disputed result kept' : 'Disputed match voided',
                "CourtFlex looked at the dispute: {$note}",
                "/matches/{$match->id}",
            );
        }

        AdminView::writeAudit(
            action: $decision === 'keep' ? 'match.dispute_kept' : 'match.dispute_voided',
            summary: ($decision === 'keep' ? 'Kept' : 'Voided').' the disputed result of '.$name,
            type: 'match',
            id: (int) $match->id,
            name: $name,
            reason: $note,
        );

        return $this->successResponse(
            data: MatchView::adminDispute($match),
            message: $decision === 'keep' ? 'Disputed result kept.' : 'Disputed match voided.',
        );
    }

    public function reverse(Request $request, GameMatch $match): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        if ($match->status !== 'completed') {
            return $this->errorResponse('Only an official result can be reversed.', code: Response::HTTP_CONFLICT);
        }

        if ($match->tournament_id !== null) {
            return $this->errorResponse(
                'This tournament match has already moved the draw on, so it cannot be reversed.',
                code: Response::HTTP_CONFLICT,
            );
        }

        $reason = trim((string) $request->input('reason', ''));

        if (mb_strlen($reason) < 10) {
            return $this->validationError([
                'reason' => ['Say why in a sentence (at least 10 characters).'],
            ]);
        }

        /** @var \App\Models\User $admin */
        $admin = $request->user();
        $undone = MatchEngine::reverse($match, (int) $admin->id, $reason);
        $name = $this->matchName($match);

        AdminView::writeAudit(
            action: 'match.reversed',
            summary: 'Reversed the result of '.$name.($undone > 0 ? " and undid {$undone} rating changes" : ''),
            type: 'match',
            id: (int) $match->id,
            name: $name,
            reason: $reason,
        );

        return $this->successResponse(
            data: ['reversed' => (string) $match->id, 'rating_changes_undone' => $undone],
            message: 'Match result reversed.',
        );
    }

    private function matchName(GameMatch $match): string
    {
        $match->loadMissing('players.user');
        $teams = [[], []];

        foreach ($match->players as $player) {
            $teams[(int) $player->side][] = $player->user?->name ?? 'Player';
        }

        return implode(' & ', $teams[0]).' vs '.implode(' & ', $teams[1]);
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
