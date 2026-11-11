<?php

namespace App\Http\Controllers\Api\V1\Players;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Http\Traits\ApiResponse;
use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AnnouncementService $announcements
    ) {}

    /**
     * Paginated feed of announcements visible to the player.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $feed = $this->announcements->feedForPlayer($request->user(), $perPage);

        return $this->successResponse(
            data: AnnouncementResource::collection($feed),
            meta: [
                'current_page' => $feed->currentPage(),
                'last_page' => $feed->lastPage(),
                'per_page' => $feed->perPage(),
                'total' => $feed->total(),
                'unread_count' => $this->announcements->unreadCount($request->user()),
            ]
        );
    }

    /**
     * Show announcement details when visible to the player.
     */
    public function show(Request $request, Announcement $announcement): JsonResponse
    {
        if (! $this->announcements->canPlayerView($request->user(), $announcement)) {
            return $this->errorResponse('Announcement not found.', code: 404);
        }

        return $this->successResponse(
            data: new AnnouncementResource($announcement->load(['creator', 'reads' => fn ($query) => $query->where('user_id', $request->user()->id)])),
            message: 'Announcement details retrieved.'
        );
    }

    /**
     * Mark the announcement as read for the player (idempotent).
     */
    public function markRead(Request $request, Announcement $announcement): JsonResponse
    {
        if (! $this->announcements->canPlayerView($request->user(), $announcement)) {
            return $this->errorResponse('Announcement not found.', code: 404);
        }

        $this->announcements->markRead($request->user(), $announcement);

        return $this->successResponse(
            data: new AnnouncementResource($announcement->load(['creator', 'reads' => fn ($query) => $query->where('user_id', $request->user()->id)])),
            message: 'Announcement marked as read.'
        );
    }

    /**
     * Number of visible announcements the player has not read yet.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->successResponse(
            data: ['unread' => $this->announcements->unreadCount($request->user())],
            message: 'Unread announcement count retrieved.'
        );
    }
}
