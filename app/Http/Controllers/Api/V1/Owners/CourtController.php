<?php

namespace App\Http\Controllers\Api\V1\Owners;

use App\Http\Controllers\Controller;
use App\Http\Requests\Courts\AddCourtImageRequest;
use App\Http\Requests\Courts\ReorderCourtImagesRequest;
use App\Http\Requests\Courts\StoreCourtRequest;
use App\Http\Requests\Courts\UpdateCourtRequest;
use App\Http\Resources\CourtResource;
use App\Http\Traits\ApiResponse;
use App\Models\Court;
use App\Models\CourtImage;
use App\Models\Facility;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class CourtController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext,
        protected FileUploadService $fileUpload,
        protected AuditLogService $auditLog
    ) {}

    /**
     * List all courts under owner's tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();

        $courts = Court::where('tenant_id', $tenantId)
            ->when($request->facility_id, fn ($q, $f) => $q->where('facility_id', $f))
            ->with('images')
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse(
            data: CourtResource::collection($courts),
            meta: [
                'current_page' => $courts->currentPage(),
                'total' => $courts->total(),
            ]
        );
    }

    /**
     * Create a court under owner's facility with 1 to 5 gallery images.
     */
    public function store(StoreCourtRequest $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();
        $validated = $request->validated();

        $facility = Facility::where('id', $validated['facility_id'])
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $facility) {
            return $this->errorResponse('Facility does not belong to this tenant.', code: Response::HTTP_FORBIDDEN);
        }

        // Uploaded gallery images are persisted through the relation below.
        unset($validated['images']);

        $court = Court::create(array_merge($validated, [
            'tenant_id' => $tenantId,
            'is_active' => true,
        ]));

        $this->persistImages($court, $request->file('images'));

        return $this->successResponse(
            data: new CourtResource($court->load('images')),
            message: 'Court created successfully.',
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Show court details.
     */
    public function show(Court $court): JsonResponse
    {
        if ($court->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized court access.', code: Response::HTTP_FORBIDDEN);
        }

        return $this->successResponse(
            data: new CourtResource($court->load(['facility', 'operatingHours', 'images'])),
            message: 'Court details retrieved.'
        );
    }

    /**
     * Update court.
     */
    public function update(UpdateCourtRequest $request, Court $court): JsonResponse
    {
        if ($court->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized court access.', code: Response::HTTP_FORBIDDEN);
        }

        $court->update($request->validated());

        return $this->successResponse(
            data: new CourtResource($court),
            message: 'Court updated successfully.'
        );
    }

    /**
     * Delete court.
     */
    public function destroy(Court $court): JsonResponse
    {
        if ($court->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized court access.', code: Response::HTTP_FORBIDDEN);
        }

        $court->delete();

        return $this->successResponse(
            message: 'Court deleted successfully.'
        );
    }

    /**
     * Add images to a court without exceeding five in total.
     */
    public function addImages(AddCourtImageRequest $request, Court $court): JsonResponse
    {
        if ($court->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized court access.', code: Response::HTTP_FORBIDDEN);
        }

        $incoming = count((array) $request->file('images'));
        $existing = $court->images()->count();

        if ($existing + $incoming > CourtImage::MAX_PER_COURT) {
            return $this->errorResponse(
                'A court can hold at most '.CourtImage::MAX_PER_COURT.' images; this court already has '.$existing.'.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $this->persistImages($court, $request->file('images'));

        $this->auditLog->log('court_images_added', $court, $court->tenant_id, $request->user(), null, [
            'court_id' => $court->id,
            'added' => $incoming,
            'total' => $court->images()->count(),
        ]);

        return $this->successResponse(
            data: new CourtResource($court->load('images')),
            message: 'Court images added successfully.',
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Delete a court image while keeping at least one in place.
     */
    public function deleteImage(Court $court, CourtImage $image): JsonResponse
    {
        if ($court->tenant_id !== $this->tenantContext->getTenantId() || $image->court_id !== $court->id) {
            return $this->errorResponse('Unauthorized court access.', code: Response::HTTP_FORBIDDEN);
        }

        if ($court->images()->count() <= 1) {
            return $this->errorResponse('A court must keep at least one image.', code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->fileUpload->delete($image->image_path);
        $image->delete();

        $this->auditLog->log('court_image_deleted', $court, $court->tenant_id, auth()->user(), [
            'court_image_id' => $image->id,
            'image_path' => $image->image_path,
        ], null);

        return $this->successResponse(
            data: new CourtResource($court->load('images')),
            message: 'Court image deleted successfully.'
        );
    }

    /**
     * Reorder the court gallery by supplying image ids in the wanted order.
     */
    public function reorderImages(ReorderCourtImagesRequest $request, Court $court): JsonResponse
    {
        if ($court->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized court access.', code: Response::HTTP_FORBIDDEN);
        }

        $imageIds = array_values((array) $request->validated('image_ids'));

        foreach ($imageIds as $index => $imageId) {
            CourtImage::query()
                ->where('id', $imageId)
                ->where('court_id', $court->id)
                ->update(['sort_order' => $index + 1]);
        }

        $this->auditLog->log('court_images_reordered', $court, $court->tenant_id, $request->user(), null, [
            'court_id' => $court->id,
            'image_ids' => $imageIds,
        ]);

        return $this->successResponse(
            data: new CourtResource($court->load('images')),
            message: 'Court images reordered successfully.'
        );
    }

    /**
     * Upload and attach gallery images in the given order.
     *
     * @param  array<int, UploadedFile>|null  $files
     */
    protected function persistImages(Court $court, ?array $files): void
    {
        $nextOrder = (int) $court->images()->max('sort_order');

        foreach ((array) $files as $file) {
            $nextOrder++;

            $court->images()->create([
                'image_path' => $this->fileUpload->upload($file, 'courts'),
                'sort_order' => $nextOrder,
            ]);
        }
    }
}
