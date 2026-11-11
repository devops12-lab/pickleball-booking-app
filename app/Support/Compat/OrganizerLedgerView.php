<?php

namespace App\Support\Compat;

use App\Models\ClubMembershipPayment;
use App\Models\OpenPlayFeePayment;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Money received by a venue or community, and the revenue summary over it.
 *
 * Venues collect booking payments, their own session fees, and court fees for
 * community sessions. Communities collect their session fees and member dues.
 * Refunds reduce the net revenue but never erase the original entry.
 */
class OrganizerLedgerView
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function entries(string $type, int $id, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->startOfDay();
        $end = $to->endOfDay();
        $out = [];

        if ($type === 'venue') {
            Payment::query()
                ->with(['booking.user', 'booking.court', 'user', 'refunds'])
                ->whereIn('status', ['paid', 'verified', 'refunded'])
                ->whereBetween('created_at', [$start, $end])
                ->whereHas('booking', fn ($query) => $query->where('tenant_id', $id))
                ->get()
                ->each(function (Payment $payment) use (&$out): void {
                    $booking = $payment->booking;
                    if ($booking === null) {
                        return;
                    }

                    $refunded = (float) $payment->refunds->sum('amount');
                    $payer = $booking->user?->name
                        ?: ($booking->guest_name ?: 'Guest');
                    $court = $booking->court?->name ?? 'Court';

                    $out[] = [
                        'id' => 'booking:'.$payment->id,
                        'kind' => 'court_booking',
                        'description' => $court.' booking '.$booking->booking_reference,
                        'payer' => (string) $payer,
                        'amount' => Contract::money($payment->amount),
                        'refunded' => Contract::money($refunded),
                        'currency' => Contract::CURRENCY,
                        'method' => Contract::paymentMethod($payment->payment_method),
                        'reference_number' => $payment->transaction_reference,
                        'received_by' => Contract::person($payment->user) ?? ['id' => '0', 'name' => 'System'],
                        'received_at' => $payment->created_at->toIso8601String(),
                    ];
                });
        }

        $fees = OpenPlayFeePayment::query()
            ->with(['session', 'payer', 'receiver', 'refunds'])
            ->whereBetween('received_at', [$start, $end])
            ->where(function ($query) use ($type, $id): void {
                if ($type === 'community') {
                    $query->where('kind', 'session_fee')
                        ->whereHas('session', fn ($session) => $session
                            ->where('organizer_type', 'community')
                            ->where('organizer_id', $id));

                    return;
                }

                $query->where(function ($q) use ($id): void {
                    $q->where('kind', 'session_fee')
                        ->whereHas('session', fn ($session) => $session
                            ->where('organizer_type', 'venue')
                            ->where('organizer_id', $id));
                })->orWhere(function ($q) use ($id): void {
                    $q->where('kind', 'court_fee')
                        ->whereHas('session', fn ($session) => $session->where('tenant_id', $id));
                });
            })
            ->get();

        $fees->each(function (OpenPlayFeePayment $payment) use (&$out): void {
            $session = $payment->session;
            if ($session === null) {
                return;
            }

            $refunded = (float) $payment->refunds->sum('amount');
            $description = $payment->kind === 'court_fee'
                ? 'Court fee: '.$session->title
                : $session->title.' session fee';

            $out[] = [
                'id' => 'fee:'.$payment->id,
                'kind' => $payment->kind,
                'description' => $description,
                'payer' => (string) ($payment->payer?->name ?? 'Player'),
                'amount' => Contract::money($payment->amount),
                'refunded' => Contract::money($refunded),
                'currency' => Contract::CURRENCY,
                'method' => Contract::paymentMethod($payment->method),
                'reference_number' => $payment->reference_number,
                'received_by' => Contract::person($payment->receiver) ?? ['id' => '0', 'name' => 'System'],
                'received_at' => $payment->received_at->toIso8601String(),
            ];
        });

        if ($type === 'community') {
            ClubMembershipPayment::query()
                ->with(['payer', 'receiver'])
                ->where('club_id', $id)
                ->whereBetween('received_at', [$start, $end])
                ->get()
                ->each(function (ClubMembershipPayment $payment) use (&$out): void {
                    $out[] = [
                        'id' => 'dues:'.$payment->id,
                        'kind' => 'membership_dues',
                        'description' => (string) $payment->description,
                        'payer' => (string) ($payment->payer?->name ?? 'Member'),
                        'amount' => Contract::money($payment->amount),
                        'refunded' => Contract::money(0),
                        'currency' => Contract::CURRENCY,
                        'method' => Contract::paymentMethod($payment->method),
                        'reference_number' => $payment->reference_number,
                        'received_by' => Contract::person($payment->receiver) ?? ['id' => '0', 'name' => 'System'],
                        'received_at' => $payment->received_at->toIso8601String(),
                    ];
                });
        }

        usort($out, fn (array $a, array $b): int => strcmp($b['received_at'], $a['received_at']));

        return $out;
    }

    /**
     * @param  Collection<int, array<string, mixed>>|list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    public static function report(string $from, string $to, Collection|array $entries): array
    {
        $total = 0.0;
        $refunds = 0.0;
        $kinds = [];
        $methods = [];

        foreach ($entries as $entry) {
            $net = (float) $entry['amount'] - (float) $entry['refunded'];
            $total += $net;
            $refunds += (float) $entry['refunded'];

            $kind = (string) $entry['kind'];
            $kinds[$kind] ??= ['amount' => 0.0, 'count' => 0];
            $kinds[$kind]['amount'] += $net;
            $kinds[$kind]['count']++;

            $method = (string) $entry['method'];
            $methods[$method] = ($methods[$method] ?? 0.0) + $net;
        }

        return [
            'from' => $from,
            'to' => $to,
            'currency' => Contract::CURRENCY,
            'total' => Contract::money($total),
            'refunds' => Contract::money($refunds),
            'by_kind' => collect($kinds)
                ->map(fn (array $value, string $kind): array => [
                    'kind' => $kind,
                    'amount' => Contract::money($value['amount']),
                    'count' => $value['count'],
                ])
                ->values()
                ->all(),
            'by_method' => collect($methods)
                ->map(fn (float $amount, string $method): array => [
                    'method' => $method,
                    'amount' => Contract::money($amount),
                ])
                ->values()
                ->all(),
        ];
    }
}
