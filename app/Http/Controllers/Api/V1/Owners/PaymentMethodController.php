<?php

namespace App\Http\Controllers\Api\V1\Owners;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentMethods\StoreTenantPaymentMethodRequest;
use App\Http\Requests\PaymentMethods\UpdateTenantPaymentMethodRequest;
use App\Http\Resources\TenantPaymentMethodResource;
use App\Http\Traits\ApiResponse;
use App\Models\TenantPaymentMethod;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentMethodController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext,
        protected FileUploadService $fileUpload,
        protected AuditLogService $auditLog
    ) {}

    /**
     * List the payment channels this facility accepts.
     */
    public function index(Request $request): JsonResponse
    {
        $methods = TenantPaymentMethod::query()
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->successResponse(
            data: TenantPaymentMethodResource::collection($methods),
            message: 'Payment methods retrieved.'
        );
    }

    /**
     * Create a payment channel for the owner's facility.
     */
    public function store(StoreTenantPaymentMethodRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['tenant_id'] = $this->tenantContext->getTenantId();

        if ($request->hasFile('qr_image')) {
            $data['qr_image'] = $this->fileUpload->upload($request->file('qr_image'), 'payment-methods');
        }

        $method = TenantPaymentMethod::create($data);

        $this->auditLog->log('payment_method_created', $method, $method->tenant_id, $request->user(), null, [
            'payment_method_id' => $method->id,
            'name' => $method->name,
            'type' => $method->type->value,
        ]);

        return $this->successResponse(
            data: new TenantPaymentMethodResource($method),
            message: 'Payment method created successfully.',
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Show a payment method.
     */
    public function show(TenantPaymentMethod $paymentMethod): JsonResponse
    {
        if ($paymentMethod->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized payment method access.', code: Response::HTTP_FORBIDDEN);
        }

        return $this->successResponse(
            data: new TenantPaymentMethodResource($paymentMethod),
            message: 'Payment method details retrieved.'
        );
    }

    /**
     * Update a payment method.
     */
    public function update(UpdateTenantPaymentMethodRequest $request, TenantPaymentMethod $paymentMethod): JsonResponse
    {
        if ($paymentMethod->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized payment method access.', code: Response::HTTP_FORBIDDEN);
        }

        $data = $request->validated();
        unset($data['qr_image']);

        if ($request->hasFile('qr_image')) {
            $this->fileUpload->delete($paymentMethod->qr_image);
            $data['qr_image'] = $this->fileUpload->upload($request->file('qr_image'), 'payment-methods');
        }

        $old = ['name' => $paymentMethod->name, 'type' => $paymentMethod->type->value, 'is_active' => $paymentMethod->is_active];

        $paymentMethod->update($data);

        $this->auditLog->log('payment_method_updated', $paymentMethod, $paymentMethod->tenant_id, $request->user(), $old, [
            'payment_method_id' => $paymentMethod->id,
            'name' => $paymentMethod->name,
            'type' => $paymentMethod->type->value,
            'is_active' => $paymentMethod->is_active,
        ]);

        return $this->successResponse(
            data: new TenantPaymentMethodResource($paymentMethod->refresh()),
            message: 'Payment method updated successfully.'
        );
    }

    /**
     * Delete a payment method.
     */
    public function destroy(TenantPaymentMethod $paymentMethod): JsonResponse
    {
        if ($paymentMethod->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized payment method access.', code: Response::HTTP_FORBIDDEN);
        }

        $snapshot = ['payment_method_id' => $paymentMethod->id, 'name' => $paymentMethod->name, 'type' => $paymentMethod->type->value];

        $this->fileUpload->delete($paymentMethod->qr_image);
        $paymentMethod->delete();

        $this->auditLog->log('payment_method_deleted', null, $this->tenantContext->getTenantId(), auth()->user(), $snapshot, null);

        return $this->successResponse(message: 'Payment method deleted successfully.');
    }
}
