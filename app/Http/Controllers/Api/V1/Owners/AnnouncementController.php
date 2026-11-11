<?php

namespace App\Http\Controllers\Api\V1\Owners;

use App\Http\Controllers\Controller;
use App\Http\Requests\Announcements\StoreAnnouncementRequest;
use App\Http\Requests\Announcements\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Http\Traits\ApiResponse;
use App\Models\Announcement;
use App\Services\AnnouncementService;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class AnnouncementController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AnnouncementService $announcements,
        protected TenantContextService $tenantContext
    ) {}

    /**
     * List announcements belonging to the owner's tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $announcements = Announcement::query()
            ->with('creator')
            ->withCount('reads')
            ->forTenant($this->tenantContext->getTenantId())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return $this->successResponse(
            data: AnnouncementResource::collection($announcements),
            meta: [
                'current_page' => $announcements->currentPage(),
                'total' => $announcements->total(),
            ]
        );
    }

    /**
     * Create a new announcement under the owner's tenant.
     */
    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $announcement = $this->announcements->create(
            $request->validatedPayload(),
            $request->user(),
            $this->tenantContext->getTenantId()
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
        if ($announcement->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized announcement access.', code: Response::HTTP_FORBIDDEN);
        }

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
        if ($announcement->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized announcement access.', code: Response::HTTP_FORBIDDEN);
        }

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
     * Delete announcement.
     */
    public function destroy(Announcement $announcement): JsonResponse
    {
        if ($announcement->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized announcement access.', code: Response::HTTP_FORBIDDEN);
        }

        $this->announcements->delete($announcement, auth()->user());

        return $this->successResponse(message: 'Announcement deleted successfully.');
    }

    /**
     * Publish a draft or scheduled announcement immediately.
     */
    public function publish(Announcement $announcement, Request $request): JsonResponse
    {
        if ($announcement->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized announcement access.', code: Response::HTTP_FORBIDDEN);
        }

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
        if ($announcement->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized announcement access.', code: Response::HTTP_FORBIDDEN);
        }

        $this->announcements->archive($announcement, $request->user());

        return $this->successResponse(
            data: new AnnouncementResource($announcement->load('creator')),
            message: 'Announcement archived successfully.'
        );
    }
}
