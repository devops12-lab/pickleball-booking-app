<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TournamentMatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tournament_id' => $this->tournament_id,
            'tournament_category_id' => $this->tournament_category_id,
            'court_id' => $this->court_id,
            'round_number' => $this->round_number,
            'match_number' => $this->match_number,
            'participant1_id' => $this->participant1_id,
            'participant2_id' => $this->participant2_id,
            'winner_id' => $this->winner_id,
            'score' => $this->score,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at?->toISOString() ?? (string) $this->scheduled_at,
            'participant1' => $this->whenLoaded('participant1'),
            'participant2' => $this->whenLoaded('participant2'),
            'winner' => $this->whenLoaded('winner'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
