<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSystemAdmin()) {
            return true;
        }

        return null;
    }

    public function view(User $user, Payment $payment): bool
    {
        if ($payment->user_id === $user->id) {
            return true;
        }

        return $user->isOwnerOf($payment->tenant_id) || $user->isStaffOf($payment->tenant_id);
    }

    public function verify(User $user, Payment $payment): bool
    {
        return $user->isOwnerOf($payment->tenant_id) || $user->isStaffOf($payment->tenant_id);
    }
}
