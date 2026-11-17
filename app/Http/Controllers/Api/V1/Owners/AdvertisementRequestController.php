<?php

namespace App\Http\Controllers\Api\V1\Owners;

use App\Enums\AdvertisementPlacement;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdvertisementRequestResource;
use App\Http\Traits\ApiResponse;
use App\Models\AdvertisementRequest;
use App\Services\FileUploadService;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdvertisementRequestController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext,
        protected FileUploadService $fileUpload
    ) {}

    public function index(Request $request): JsonResponse
    {
        $requests = AdvertisementRequest::where('requester_id', $request->user()->id)
            ->where('requester_type', 'owner')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return $this->successResponse(
            data: AdvertisementRequestResource::collection($requests),
            meta: [
                'current_page' => $requests->currentPage(),
                'total' => $requests->total(),
            ]
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'objective' => ['nullable', 'string', 'max:2000'],
            'requested_placement' => ['nullable', 'string', 'in:'.implode(',', AdvertisementPlacement::values())],
            'requested_start_date' => ['nullable', 'date', 'after_or_equal:today'],
            'requested_end_date' => ['nullable', 'date', 'after:requested_start_date'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'additional_requirements' => ['nullable', 'string', 'max:3000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        $attachmentPath = null;

        if ($request->hasFile('attachment')) {
            $attachmentPath = $this->fileUpload->upload($request->file('attachment'), 'ad-requests');
        }

        $adRequest = AdvertisementRequest::create([
            ...$validated,
            'requester_id' => $request->user()->id,
            'requester_type' => 'owner',
            'tenant_id' => $this->tenantContext->getTenantId(),
            'attachment_path' => $attachmentPath,
            'status' => 'pending',
        ]);

        return $this->successResponse(
            data: new AdvertisementRequestResource($adRequest),
            message: 'Advertisement request submitted.',
            code: Response::HTTP_CREATED
        );
    }

    public function show(Request $request, AdvertisementRequest $advertisementRequest): JsonResponse
    {
        if ($advertisementRequest->requester_id !== $request->user()->id) {
            return $this->errorResponse('Unauthorized.', code: Response::HTTP_FORBIDDEN);
        }

        return $this->successResponse(
            data: new AdvertisementRequestResource($advertisementRequest->load(['reviewer']))
        );
    }
}
