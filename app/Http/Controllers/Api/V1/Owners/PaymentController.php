<?php

namespace App\Http\Controllers\Api\V1\Owners;

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
     * List all facility payments.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();

        $payments = Payment::with('user:id,name,email')
            ->where('tenant_id', $tenantId)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->successResponse(
            data: PaymentResource::collection($payments),
            meta: [
                'current_page' => $payments->currentPage(),
                'total' => $payments->total(),
            ]
        );
    }

    /**
     * Verify payment directly by Owner.
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
            $validated['notes'] ?? 'Approved by Owner'
        );

        return $this->successResponse(
            data: new PaymentResource($payment->fresh()),
            message: 'Payment has been successfully verified by facility owner.'
        );
    }
}
