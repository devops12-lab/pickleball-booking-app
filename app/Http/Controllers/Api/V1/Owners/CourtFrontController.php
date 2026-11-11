<?php

namespace App\Http\Controllers\Api\V1\Owners;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourtFront\UpdateCourtFrontRequest;
use App\Http\Requests\CourtFront\UploadCourtFrontImageRequest;
use App\Http\Resources\CourtFrontResource;
use App\Http\Traits\ApiResponse;
use App\Models\CourtFront;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

class CourtFrontController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext,
        protected FileUploadService $fileUpload,
        protected AuditLogService $auditLog
    ) {}

    /**
     * Show the store front of the owner's facility; created with defaults on
     * first access so the facility always has exactly one front page.
     */
    public function show(): JsonResponse
    {
        return $this->successResponse(
            data: new CourtFrontResource($this->firstOrCreateFront()->load('tenant')),
            message: 'Court front retrieved.'
        );
    }

    /**
     * Update the store front details and contact information.
     */
    public function update(UpdateCourtFrontRequest $request): JsonResponse
    {
        $front = $this->firstOrCreateFront();

        $old = $front->only(['tagline', 'description', 'contact_email', 'contact_phone', 'contact_address', 'website', 'facebook', 'instagram', 'is_open']);

        $front->update($request->validated());

        $this->auditLog->log('court_front_updated', $front, $front->tenant_id, $request->user(), $old, $front->only(['tagline', 'description', 'contact_email', 'contact_phone', 'contact_address', 'website', 'facebook', 'instagram', 'is_open']));

        return $this->successResponse(
            data: new CourtFrontResource($front->refresh()->load('tenant')),
            message: 'Court front updated successfully.'
        );
    }

    /**
     * Upload or replace the store front logo.
     */
    public function uploadLogo(UploadCourtFrontImageRequest $request): JsonResponse
    {
        $front = $this->firstOrCreateFront();

        $this->replaceImage($front, 'logo', $request->file('image'));

        $this->auditLog->log('court_front_logo_updated', $front, $front->tenant_id, $request->user(), null, [
            'court_front_id' => $front->id,
            'logo_path' => $front->logo,
        ]);

        return $this->successResponse(
            data: new CourtFrontResource($front->refresh()->load('tenant')),
            message: 'Court front logo updated successfully.'
        );
    }

    /**
     * Upload or replace the store front cover image.
     */
    public function uploadCover(UploadCourtFrontImageRequest $request): JsonResponse
    {
        $front = $this->firstOrCreateFront();

        $this->replaceImage($front, 'cover_image', $request->file('image'));

        $this->auditLog->log('court_front_cover_updated', $front, $front->tenant_id, $request->user(), null, [
            'court_front_id' => $front->id,
            'cover_image_path' => $front->cover_image,
        ]);

        return $this->successResponse(
            data: new CourtFrontResource($front->refresh()->load('tenant')),
            message: 'Court front cover image updated successfully.'
        );
    }

    /**
     * The single store front row for the active tenant.
     */
    protected function firstOrCreateFront(): CourtFront
    {
        return CourtFront::firstOrCreate(
            ['tenant_id' => $this->tenantContext->getTenantId()]
        )->refresh();
    }

    /**
     * @param  UploadedFile  $file
     */
    protected function replaceImage(CourtFront $front, string $column, $file): void
    {
        $this->fileUpload->delete($front->{$column});

        $front->update([
            $column => $this->fileUpload->upload($file, 'court-fronts'),
        ]);
    }
}
