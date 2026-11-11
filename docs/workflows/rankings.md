---
title: Rankings and statistics
category: Tournaments & rankings
status: Partially Implemented
order: 20
summary: Read-only leaderboards and player statistics. Nothing in the API updates them after a match.
sources:
  - app/Http/Controllers/Api/V1/Public/RankingController.php
  - app/Http/Controllers/Api/V1/Players/RankingController.php
  - app/Http/Controllers/Api/V1/Players/DashboardController.php
  - app/Services/Auth/AccountProvisioningService.php
  - app/Models/PlayerStatistic.php
  - app/Models/PlayerRanking.php
  - app/Models/MatchResult.php
  - database/migrations/2026_11_05_100008_create_rankings_and_statistics_tables.php
---

# Rankings and statistics

## Module overview

| | |
| --- | --- |
| Public | `GET /public/rankings` |
| Players | `GET /players/rankings`, `GET /players/statistics`, `GET /players/dashboard` |
| Tables | `player_statistics` (one per user), `player_rankings` (history by category and date), `match_results` |

## How data gets in

| Table | Written by |
| --- | --- |
| `player_statistics` | `AccountProvisioningService` creates one row with `current_rating` 1200 when a user is created (player, owner, staff or Google sign-up). Nothing updates it afterwards. |
| `player_rankings` | Nothing. No controller, service or seeder writes to it. |
| `match_results` | Nothing. No controller, service or seeder writes to it. |

Because [match scoring](tournaments-and-matches.md) does not touch these tables, rankings and win/loss records never change through the API.

## Read flows

- **Public rankings** read `player_rankings` for the `category` query parameter (default `general`), ordered by rank. Because that table is empty, the endpoint falls back to `player_statistics` ordered by `current_rating`, which is 1200 for every user.
- **Player rankings and statistics** read the current user's own rows.

Status: **Partially Implemented**. The read side works; the update side (rating calculation, ranking snapshots, verified match results) is planned.
