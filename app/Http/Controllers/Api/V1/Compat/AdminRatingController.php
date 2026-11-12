<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesPlatformAdmin;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\GeoLocation;
use App\Models\PlayerProfile;
use App\Models\PlayerStatistic;
use App\Models\RatingAdjustment;
use App\Support\Compat\AdminView;
use App\Support\Compat\RatingRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super Admin rating controls: a player rating list, manual corrections and
 * the four rules used by the ELO calculation.
 */
class AdminRatingController extends Controller
{
    use ApiResponse, AuthorizesPlatformAdmin;

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $q = strtolower(trim((string) $request->query('q', '')));
        $rules = RatingRules::current();
        $profiles = PlayerProfile::query()
            ->with('user')
            ->whereHas('user', function ($query) use ($q): void {
                if ($q !== '') {
                    $query->where(function ($inner) use ($q): void {
                        $inner->whereRaw('LOWER(name) LIKE ?', ['%'.$q.'%'])
                            ->orWhereRaw('LOWER(email) LIKE ?', ['%'.$q.'%']);
                    });
                }
            })
            ->get();

        $stats = PlayerStatistic::query()
            ->whereIn('user_id', $profiles->pluck('user_id'))
            ->get()
            ->keyBy('user_id');

        $filtered = $profiles->filter(fn (PlayerProfile $profile): bool => $profile->user !== null);
        $geoChains = GeoLocation::chains($filtered->pluck('barangay_id')->all());

        $rows = $filtered
            ->map(function (PlayerProfile $profile) use ($stats, $rules, $geoChains): array {
                $stat = $stats->get($profile->user_id);
                $side = function (string $type) use ($stat, $rules): array {
                    $rating = (int) round((float) ($stat?->{$type.'_rating'} ?? $stat?->current_rating ?? $rules['initial']));
                    $games = (int) ($stat?->{$type.'_matches'} ?? 0);

                    return [
                        'rating' => $rating,
                        'games' => $games,
                        'provisional' => $games < $rules['provisional_matches'],
                    ];
                };
                $chain = $geoChains[$profile->barangay_id] ?? null;

                return [
                    'player' => [
                        'id' => (string) $profile->user_id,
                        'name' => (string) ($profile->display_name ?: $profile->user?->name),
                        'email' => (string) ($profile->user?->email ?? ''),
                        'city' => $chain === null ? null : (string) $chain['city']->name,
                    ],
                    'singles' => $side('singles'),
                    'doubles' => $side('doubles'),
                ];
            })
            ->sortByDesc(fn (array $row): int => $row['doubles']['rating'])
            ->take(50)
            ->values();

        return $this->successResponse(data: $rows->all());
    }

    public function adjustments(): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $rows = RatingAdjustment::query()
            ->with(['player', 'adjuster'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (RatingAdjustment $row): array => [
                'id' => (string) $row->id,
                'player' => [
                    'id' => (string) $row->player_id,
                    'name' => (string) ($row->player?->name ?? 'Player'),
                ],
                'type' => (string) $row->type,
                'old' => (int) $row->old_rating,
                'new' => (int) $row->new_rating,
                'reason' => (string) $row->reason,
                'by' => [
                    'id' => (string) $row->adjusted_by,
                    'name' => (string) ($row->adjuster?->name ?? 'Super Admin'),
                ],
                'at' => $row->created_at->toIso8601String(),
            ])
            ->all();

        return $this->successResponse(data: $rows);
    }

    public function adjust(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $data = $request->validate([
            'player_id' => ['required', 'integer'],
            'type' => ['required', 'in:singles,doubles'],
            'rating' => ['required', 'integer', 'between:100,3000'],
            'reason' => ['required', 'string', 'min:10'],
        ]);

        $profile = PlayerProfile::query()
            ->with('user')
            ->where('user_id', $data['player_id'])
            ->first();

        if ($profile === null || $profile->user === null) {
            return $this->validationError(['player_id' => ['Choose a player.']]);
        }

        $rules = RatingRules::current();
        $stat = PlayerStatistic::query()->firstOrCreate(
            ['user_id' => $profile->user_id],
            [
                'current_rating' => $rules['initial'],
                'singles_rating' => $rules['initial'],
                'doubles_rating' => $rules['initial'],
            ],
        );
        $field = $data['type'].'_rating';
        $old = (int) round((float) ($stat->{$field} ?? $stat->current_rating ?? $rules['initial']));
        $new = (int) $data['rating'];

        if ($old === $new) {
            return $this->errorResponse(
                "{$profile->user->name}'s {$data['type']} rating is already {$old}.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $stat->{$field} = $new;
        $stat->current_rating = $new;
        $stat->save();

        $adjustment = RatingAdjustment::query()->create([
            'player_id' => $profile->user_id,
            'adjusted_by' => $request->user()->id,
            'type' => $data['type'],
            'old_rating' => $old,
            'new_rating' => $new,
            'reason' => $data['reason'],
        ]);

        AdminView::writeAudit(
            action: 'rating.adjusted',
            summary: "Changed {$profile->user->name}'s {$data['type']} rating from {$old} to {$new}",
            type: 'user',
            id: (int) $profile->user_id,
            name: (string) $profile->user->name,
            reason: $data['reason'],
        );

        return $this->successResponse(
            data: [
                'player_id' => (string) $profile->user_id,
                'type' => (string) $adjustment->type,
                'old' => $old,
                'new' => $new,
            ],
            message: 'Rating adjusted.',
        );
    }

    public function rules(): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        return $this->successResponse(data: RatingRules::current());
    }

    public function updateRules(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $data = $request->validate([
            'initial' => ['required', 'integer', 'between:800,2000'],
            'provisional_matches' => ['required', 'integer', 'between:1,20'],
            'k_provisional' => ['required', 'integer', 'between:8,64'],
            'k_established' => ['required', 'integer', 'between:8,64'],
        ]);

        if ($data['k_established'] > $data['k_provisional']) {
            return $this->validationError([
                'k_established' => [
                    'Make it no higher than the provisional K-factor: new players should move faster.',
                ],
            ]);
        }

        $before = RatingRules::current();
        $after = RatingRules::save($data);
        $labels = [
            'initial' => 'starting rating',
            'provisional_matches' => 'matches to be ranked',
            'k_provisional' => 'K-factor while provisional',
            'k_established' => 'K-factor after',
        ];
        $changes = [];

        foreach ($labels as $key => $label) {
            if ($before[$key] !== $after[$key]) {
                $changes[] = "{$label} {$before[$key]} → {$after[$key]}";
            }
        }

        if ($changes !== []) {
            AdminView::writeAudit(
                action: 'rating.rules_changed',
                summary: 'Changed rating rules: '.implode(', ', $changes),
                type: 'settings',
                id: 'rating-rules',
                name: 'Rating rules',
            );
        }

        return $this->successResponse(data: $after, message: 'Rating rules saved.');
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
