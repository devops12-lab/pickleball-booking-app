<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Traits\ApiResponse;
use App\Models\Payment;
use App\Services\PaymentVerificationService;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext,
        protected PaymentVerificationService $verificationService
    ) {}

    /**
     * List pending payments waiting for staff verification.
     */
    public function pending(): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();

        $payments = Payment::with('user:id,name,email')
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->latest()
            ->get();

        return $this->successResponse(
            data: PaymentResource::collection($payments),
            message: 'Pending payments retrieved.'
        );
    }

    /**
     * Verify payment proof.
     */
    public function verify(Request $request, Payment $payment): JsonResponse
    {
        if ($payment->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized payment access.', code: Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $this->verificationService->approve(
            $payment,
            $request->user(),
            $validated['notes'] ?? 'Verified by staff'
        );

        return $this->successResponse(
            data: new PaymentResource($payment->fresh()),
            message: 'Payment verified and booking confirmed.'
        );
    }
}
