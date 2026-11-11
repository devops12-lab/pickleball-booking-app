---
title: Open play
category: Clubs & open play
status: Partially Implemented
order: 20
summary: How players discover and join drop-in open play sessions, and which parts of open play management are missing.
sources:
  - routes/api/v1/players.php
  - routes/api/v1/public.php
  - app/Http/Controllers/Api/V1/Players/OpenPlayController.php
  - app/Http/Controllers/Api/V1/Public/OpenPlayController.php
  - app/Http/Resources/OpenPlaySessionResource.php
  - app/Models/OpenPlaySession.php
  - app/Models/OpenPlayParticipant.php
  - database/migrations/2026_11_05_100006_create_open_play_tables.php
  - database/seeders/DemoDataSeeder.php
---

# Open play

Open play sessions are drop-in games on a court with a participant limit, an optional fee and an optional skill range.

## Module overview

| | |
| --- | --- |
| Public | `GET /public/open-play`, `GET /public/open-play/{session}` |
| Players | `GET /players/open-play`, `POST /players/open-play/{session}/join` |
| Tables | `open_play_sessions`, `open_play_participants` |

## Where sessions come from

**No endpoint creates, edits or cancels sessions.** The only code that creates them is `DemoDataSeeder`. Status: **Partially Implemented**.

## Discover sessions

`GET /public/open-play` returns sessions with status `scheduled` or `in_progress` that start now or later, ordered by start time, with their court and facility.

`GET /public/open-play/{session}` returns any session by ID, including its participant rows (user IDs and check-in status).

## Join a session

```mermaid
sequenceDiagram
    participant P as Player
    participant C as Players OpenPlayController
    participant DB as Database
    P->>C: POST /players/open-play/{session}/join
    C->>DB: count participants
    alt count >= max_participants
        C-->>P: 422 Open play session is already fully booked.
    else space left
        C->>DB: firstOrCreate participant (check_in_status registered)
        C-->>P: 201 Successfully registered
    end
```

The unique key on (`open_play_session_id`, `user_id`) prevents duplicate registrations; joining twice returns the existing row.

## Rules that are not checked

| Rule | State |
| --- | --- |
| Session status (joining a cancelled or completed session) | Not checked |
| Session already started or past | Not checked |
| Skill range (`skill_level_min`, `skill_level_max`) | Stored, not checked |
| Fee and payment (`fee`, `payment_id`) | Stored, no payment is created |
| Capacity under concurrent joins | The count and insert are not locked, so simultaneous joins can exceed `max_participants` |

## Not implemented

Session management for owners or staff, waitlists, check-in, cancellations and leaving a session.
