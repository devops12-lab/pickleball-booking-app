<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AdvertisementRequestStatus;
use App\Enums\AdvertisementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdvertisementRequest;
use App\Http\Requests\Admin\UpdateAdvertisementRequest;
use App\Http\Resources\AdvertisementRequestResource;
use App\Http\Resources\AdvertisementResource;
use App\Http\Traits\ApiResponse;
use App\Models\Advertisement;
use App\Models\AdvertisementRequest as AdRequest;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdvertisementController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuditLogService $auditLog,
        protected FileUploadService $fileUpload
    ) {}

    /**
     * Paginated list of advertisements with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $ads = Advertisement::query()
            ->with('creator')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->placement, fn ($q, $p) => $q->where('placement', $p))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return $this->successResponse(
            data: AdvertisementResource::collection($ads),
            meta: [
                'current_page' => $ads->currentPage(),
                'total' => $ads->total(),
            ]
        );
    }

    public function store(StoreAdvertisementRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $mediaPath = null;
        $mediaType = null;

        if ($request->hasFile('media')) {
            $file = $request->file('media');
            $mediaPath = $this->fileUpload->upload($file, 'advertisements');
            $mediaType = str_starts_with($file->getMimeType(), 'video/') ? 'video' : 'image';
        }

        $ad = Advertisement::create([
            ...$validated,
            'media_path' => $mediaPath,
            'media_type' => $mediaType,
            'target_audience' => $validated['target_audience'] ?? 'all',
            'status' => AdvertisementStatus::Draft->value,
            'created_by' => $request->user()->id,
        ]);

        $this->auditLog->log('advertisement_created', $ad, null, $request->user());

        return $this->successResponse(
            data: new AdvertisementResource($ad->load('creator')),
            message: 'Advertisement created.',
            code: Response::HTTP_CREATED
        );
    }

    public function show(Advertisement $advertisement): JsonResponse
    {
        return $this->successResponse(
            data: new AdvertisementResource($advertisement->load('creator')),
            message: 'Advertisement retrieved.'
        );
    }

    public function update(UpdateAdvertisementRequest $request, Advertisement $advertisement): JsonResponse
    {
        $validated = $request->validated();

        if ($request->hasFile('media')) {
            $this->fileUpload->delete($advertisement->media_path);
            $file = $request->file('media');
            $validated['media_path'] = $this->fileUpload->upload($file, 'advertisements');
            $validated['media_type'] = str_starts_with($file->getMimeType(), 'video/') ? 'video' : 'image';
        }

        $advertisement->update($validated);

        $this->auditLog->log('advertisement_updated', $advertisement, null, $request->user());

        return $this->successResponse(
            data: new AdvertisementResource($advertisement->fresh()->load('creator')),
            message: 'Advertisement updated.'
        );
    }

    public function destroy(Advertisement $advertisement): JsonResponse
    {
        $this->fileUpload->delete($advertisement->media_path);
        $this->fileUpload->delete($advertisement->thumbnail_path);

        $this->auditLog->log('advertisement_deleted', $advertisement, null, auth()->user());

        $advertisement->delete();

        return $this->successResponse(message: 'Advertisement deleted.');
    }

    public function publish(Advertisement $advertisement): JsonResponse
    {
        $advertisement->update(['status' => AdvertisementStatus::Published->value]);

        $this->auditLog->log('advertisement_published', $advertisement, null, auth()->user());

        return $this->successResponse(
            data: new AdvertisementResource($advertisement->fresh()),
            message: 'Advertisement published.'
        );
    }

    public function pause(Advertisement $advertisement): JsonResponse
    {
        $advertisement->update(['status' => AdvertisementStatus::Paused->value]);

        $this->auditLog->log('advertisement_paused', $advertisement, null, auth()->user());

        return $this->successResponse(
            data: new AdvertisementResource($advertisement->fresh()),
            message: 'Advertisement paused.'
        );
    }

    public function updateStatus(Request $request, Advertisement $advertisement): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', AdvertisementStatus::values())],
        ]);

        $advertisement->update(['status' => $request->input('status')]);

        $this->auditLog->log('advertisement_status_changed', $advertisement, null, $request->user());

        return $this->successResponse(
            data: new AdvertisementResource($advertisement->fresh()),
            message: 'Advertisement status updated.'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Advertisement Requests (admin review)
    // ──────────────────────────────────────────────────────────────

    public function requests(Request $request): JsonResponse
    {
        $adRequests = AdRequest::with(['requester', 'tenant', 'reviewer'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return $this->successResponse(
            data: AdvertisementRequestResource::collection($adRequests),
            meta: [
                'current_page' => $adRequests->currentPage(),
                'total' => $adRequests->total(),
            ]
        );
    }

    public function showRequest(AdRequest $advertisementRequest): JsonResponse
    {
        return $this->successResponse(
            data: new AdvertisementRequestResource($advertisementRequest->load(['requester', 'tenant', 'reviewer']))
        );
    }

    public function reviewRequest(Request $request, AdRequest $advertisementRequest): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', AdvertisementRequestStatus::values())],
            'admin_response' => ['nullable', 'string', 'max:2000'],
        ]);

        $advertisementRequest->update([
            'status' => $request->input('status'),
            'admin_response' => $request->input('admin_response'),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $this->auditLog->log('advertisement_request_reviewed', $advertisementRequest, null, $request->user());

        return $this->successResponse(
            data: new AdvertisementRequestResource($advertisementRequest->fresh()->load(['requester', 'reviewer'])),
            message: 'Advertisement request reviewed.'
        );
    }
}
