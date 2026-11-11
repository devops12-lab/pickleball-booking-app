<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AnnouncementStatus;
use App\Enums\AnnouncementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Announcements\StoreAnnouncementRequest;
use App\Http\Requests\Announcements\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Http\Traits\ApiResponse;
use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class AnnouncementController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AnnouncementService $announcements
    ) {}

    /**
     * List every announcement with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $announcements = Announcement::query()
            ->with('creator')
            ->withCount('reads')
            ->when(
                in_array($request->input('status'), AnnouncementStatus::values(), true),
                fn ($query) => $query->where('status', $request->input('status'))
            )
            ->when(
                in_array($request->input('type'), AnnouncementType::values(), true),
                fn ($query) => $query->where('type', $request->input('type'))
            )
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';

                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', $search)
                        ->orWhere('body', 'like', $search);
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->successResponse(
            data: AnnouncementResource::collection($announcements),
            meta: [
                'current_page' => $announcements->currentPage(),
                'last_page' => $announcements->lastPage(),
                'per_page' => $announcements->perPage(),
                'total' => $announcements->total(),
            ]
        );
    }

    /**
     * Create a draft, scheduled or immediately published announcement.
     */
    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $announcement = $this->announcements->create(
            $request->validatedPayload(),
            $request->user(),
        );

        return $this->successResponse(
            data: new AnnouncementResource($announcement->load('creator')),
            message: $announcement->status->label().' announcement created.',
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Show announcement details.
     */
    public function show(Announcement $announcement): JsonResponse
    {
        return $this->successResponse(
            data: new AnnouncementResource($announcement->load('creator')->loadCount('reads')),
            message: 'Announcement details retrieved.'
        );
    }

    /**
     * Update announcement details.
     */
    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        try {
            $this->announcements->update($announcement, $request->validated(), $request->user());
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->successResponse(
            data: new AnnouncementResource($announcement->load('creator')),
            message: 'Announcement updated successfully.'
        );
    }

    /**
     * Delete an announcement.
     */
    public function destroy(Announcement $announcement): JsonResponse
    {
        $this->announcements->delete($announcement, auth()->user());

        return $this->successResponse(message: 'Announcement deleted successfully.');
    }

    /**
     * Publish a draft or scheduled announcement immediately.
     */
    public function publish(Announcement $announcement, Request $request): JsonResponse
    {
        try {
            $this->announcements->publish($announcement, $request->user());
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->successResponse(
            data: new AnnouncementResource($announcement->load('creator')),
            message: 'Announcement published successfully.'
        );
    }

    /**
     * Archive an announcement.
     */
    public function archive(Announcement $announcement, Request $request): JsonResponse
    {
        $this->announcements->archive($announcement, $request->user());

        return $this->successResponse(
            data: new AnnouncementResource($announcement->load('creator')),
            message: 'Announcement archived successfully.'
        );
    }
}
