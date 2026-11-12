<?php

namespace App\Services;

use App\Models\BookingStatusHistory;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Handles payment approval and rejection workflows.
 *
 * Each operation runs in a database transaction and records an audit log entry.
 * Approving a payment also transitions the associated booking to "confirmed".
 */
class PaymentVerificationService
{
    public function __construct(
        protected AuditLogService $auditLogService
    ) {}

    /**
     * Approve and verify a payment.
     */
    public function approve(Payment $payment, User $verifier, ?string $notes = null): PaymentVerification
    {
        return DB::transaction(function () use ($payment, $verifier, $notes) {
            $oldStatus = $payment->status;
            $payment->update(['status' => 'verified']);

            $verification = PaymentVerification::create([
                'payment_id' => $payment->id,
                'verified_by' => $verifier->id,
                'status' => 'approved',
                'notes' => $notes,
                'verified_at' => now(),
            ]);

            // Update associated booking to confirmed if exists
            if ($payment->booking) {
                $bookingOldStatus = $payment->booking->status;
                $payment->booking->update(['status' => 'confirmed']);

                BookingStatusHistory::create([
                    'booking_id' => $payment->booking->id,
                    'changed_by' => $verifier->id,
                    'from_status' => $bookingOldStatus,
                    'to_status' => 'confirmed',
                    'reason' => 'Payment verified: '.($notes ?? 'Approved by staff'),
                ]);
            }

            $this->auditLogService->log(
                action: 'payment.verified',
                entity: $payment,
                tenantId: $payment->tenant_id,
                user: $verifier,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => 'verified', 'notes' => $notes]
            );

            return $verification;
        });
    }

    /**
     * Reject a payment.
     */
    public function reject(Payment $payment, User $verifier, string $reason): PaymentVerification
    {
        return DB::transaction(function () use ($payment, $verifier, $reason) {
            $oldStatus = $payment->status;
            $payment->update(['status' => 'failed']);

            $verification = PaymentVerification::create([
                'payment_id' => $payment->id,
                'verified_by' => $verifier->id,
                'status' => 'rejected',
                'notes' => $reason,
                'verified_at' => now(),
            ]);

            $this->auditLogService->log(
                action: 'payment.rejected',
                entity: $payment,
                tenantId: $payment->tenant_id,
                user: $verifier,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => 'failed', 'reason' => $reason]
            );

            return $verification;
        });
    }
}
