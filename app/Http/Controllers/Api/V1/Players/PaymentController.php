<?php

namespace App\Http\Controllers\Api\V1\Players;

use App\Http\Controllers\Controller;
use App\Http\Requests\Uploads\UploadPaymentProofRequest;
use App\Http\Resources\PaymentResource;
use App\Http\Traits\ApiResponse;
use App\Models\Payment;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    use ApiResponse;

    /**
     * View personal payment history.
     */
    public function history(Request $request): JsonResponse
    {
        $payments = Payment::with('booking.court')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse(
            data: PaymentResource::collection($payments),
            meta: [
                'current_page' => $payments->currentPage(),
                'total' => $payments->total(),
            ]
        );
    }

    /**
     * Upload proof of payment for a booking payment.
     */
    public function uploadProof(UploadPaymentProofRequest $request, Payment $payment, FileUploadService $fileUploadService): JsonResponse
    {
        if ($payment->user_id !== $request->user()->id) {
            return $this->errorResponse('Unauthorized payment access.', code: Response::HTTP_FORBIDDEN);
        }

        if ($payment->status === 'verified') {
            return $this->errorResponse('Payment has already been verified.', code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($payment->proof_attachment) {
            $fileUploadService->deleteFromUrl($payment->proof_attachment);
        }

        $path = $fileUploadService->upload($request->file('proof'), 'receipts');

        $updates = [
            'proof_attachment' => $fileUploadService->url($path),
        ];
        if ($request->filled('transaction_reference')) {
            $updates['transaction_reference'] = $request->validated('transaction_reference');
        }

        $payment->update($updates);

        return $this->successResponse(
            data: new PaymentResource($payment->fresh()),
            message: 'Payment proof uploaded successfully. Awaiting verification.'
        );
    }
}
