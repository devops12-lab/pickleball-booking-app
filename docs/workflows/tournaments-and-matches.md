---
title: Tournaments and match scoring
category: Tournaments & rankings
status: Partially Implemented
order: 10
summary: How organizers create tournaments, how players register, how match scores are recorded, and what the scoring flow does not update.
sources:
  - routes/api/v1/organizers.php
  - routes/api/v1/players.php
  - routes/api/v1/public.php
  - app/Http/Controllers/Api/V1/Organizers/TournamentController.php
  - app/Http/Controllers/Api/V1/Organizers/MatchResultController.php
  - app/Http/Controllers/Api/V1/Players/TournamentController.php
  - app/Http/Controllers/Api/V1/Public/TournamentController.php
  - app/Http/Requests/Tournaments/StoreTournamentRequest.php
  - app/Http/Requests/Tournaments/RegisterTournamentRequest.php
  - app/Http/Requests/Tournaments/SubmitMatchScoreRequest.php
  - app/Models/Tournament.php
  - app/Models/TournamentMatch.php
  - database/migrations/2026_11_05_100007_create_tournaments_tables.php
---

# Tournaments and match scoring

## Module overview

| | |
| --- | --- |
| Organizers | CRUD on `/organizers/tournaments`, `POST /organizers/tournaments/{tournament}/matches/{match}/results` |
| Players | `GET /players/tournaments`, `POST /players/tournaments/{tournament}/register` |
| Public | `GET /public/tournaments[/{tournament}]` |
| Tables | `tournaments`, `tournament_categories`, `tournament_participants`, `tournament_matches`, `tournament_brackets` |

## Create a tournament (organizer)

1. `StoreTournamentRequest` validates `name`, `format` (`single_elimination`, `double_elimination` or `round_robin`), dates (`ends_at` after `starts_at`, `registration_deadline` before `starts_at`), `entry_fee`, `max_teams` (4 to 128) and an optional `tenant_id` that must exist.
2. The tenant is chosen in this order: the tenant context (never set on organizer routes, which have no `tenant.context` middleware), then the `tenant_id` input, then **the first tenant in the database**.
3. A slug unique within the tenant is generated, and the tournament is created with status `upcoming` and the user as `organizer_id`.

**Integrity note:** the organizer's membership in the chosen tenant is not checked, so an organizer can create a tournament under any tenant. Without `tenant_id`, it silently lands in the first tenant.

Show, update and delete require the user to be the tournament's `organizer_id` or a system admin.

## Register (player)

`POST /players/tournaments/{tournament}/register` validates an optional `tournament_category_id`, `partner_id` and `team_name`, then `firstOrCreate`s a `tournament_participants` row with status `registered`.

Not checked:

- tournament status or `registration_deadline`;
- `max_teams` capacity;
- that the category belongs to this tournament;
- `entry_fee` (no payment is created).

## Record a match score (organizer)

```mermaid
sequenceDiagram
    participant O as Organizer
    participant R as Route (scopeBindings)
    participant Req as SubmitMatchScoreRequest
    participant C as MatchResultController
    participant DB as Database

    O->>R: POST /organizers/tournaments/{t}/matches/{m}/results
    R->>DB: resolve tournament, then match scoped to it
    R-->>O: 404 if the match is not in the tournament
    R->>Req: score (string, max 50), winner_id (exists in tournament_participants), scores_detail (array)
    Req->>C: submitScore()
    C->>C: user must be organizer_id or system admin (403)
    C->>DB: update match: score, winner_id, status completed
    C-->>O: 200 Match score recorded successfully.
```

What scoring does **not** do:

- check that `winner_id` is one of the two participants in this match (any participant ID in the table passes validation);
- save `scores_detail`;
- create a `match_results` row;
- update `player_statistics`, `player_rankings` or brackets;
- advance a winner to the next round.

## Not implemented

Bracket generation, seeding, match scheduling, categories management, live scoring and rating calculation are listed in the roadmap but have no code. No controller, service or seeder creates matches or categories, so the score endpoint can only act on rows inserted directly into the database.
