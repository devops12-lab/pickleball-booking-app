<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\ClubMembershipPayment;
use App\Models\ClubMembershipPlan;
use App\Support\Compat\CommunityView;
use App\Support\Compat\Contract;
use App\Support\Compat\MembershipView;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Club membership endpoints from packages/mocks/src/handlers-open-play.ts.
 *
 * Only communities have members. A venue asks the SPA to use its staff page,
 * so every venue variant answers 404 instead of pretending to be a club.
 */
class MembershipController extends Controller
{
    use ApiResponse;

    /**
     * `POST /organizers/:type/:id/join`
     */
    public function join(Request $request, string $type, string $id): JsonResponse
    {
        $club = $this->community($type, $id);

        if ($club === null) {
            return $this->noClub($type);
        }

        $viewer = (int) $request->user()->id;
        $status = $club->status ?: 'active';

        if ($status !== 'active') {
            return $this->errorResponse(
                message: "This club isn't open to members yet.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $existing = ClubMember::query()
            ->with(['plan', 'payments'])
            ->where('club_id', $club->id)
            ->where('user_id', $viewer)
            ->first();

        if ($existing !== null) {
            $current = MembershipView::status($existing);

            if (in_array($current, ['pending', 'active', 'suspended'], true)) {
                return $this->errorResponse(
                    message: match ($current) {
                        'pending' => 'Your request to join is already waiting for the club.',
                        'suspended' => 'Your membership is suspended. Contact the club.',
                        default => "You're already a member.",
                    },
                    code: Response::HTTP_CONFLICT,
                );
            }
        }

        $plans = $club->membershipPlans()->where('is_active', true)->get();
        $requestedPlan = filled($request->input('plan_id'))
            ? $plans->firstWhere('id', (int) $request->input('plan_id'))
            : ($plans->count() === 1 ? $plans->first() : null);

        $errors = $this->applicationErrors($request);
        $price = (float) ($requestedPlan?->price ?? 0);
        $methods = $this->payMethods($club);
        $method = $request->input('payment_method');

        if ($plans->isNotEmpty() && $requestedPlan === null) {
            $errors['plan_id'] = ['Choose a membership plan.'];
        }

        if ($price > 0 && (! is_string($method) || ! in_array($method, $methods, true))) {
            $errors['payment_method'] = [
                $methods === []
                    ? "This club hasn't set up payments yet. Ask an admin."
                    : "Choose how you'll pay.",
            ];
        }

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $application = $this->cleanApplication($request);
        $instant = $price <= 0 && ($club->join_policy ?: 'open') === 'open';
        $paid = $price > 0;
        $now = now();

        $fields = [
            'role' => $existing?->role === 'owner' ? 'owner' : 'member',
            'plan_id' => $requestedPlan?->id,
            'requested_at' => $now,
            'status' => $instant ? 'active' : 'pending',
            'starts_at' => $instant ? $now : null,
            'expires_at' => $instant && $requestedPlan?->duration_days
                ? $now->copy()->addDays((int) $requestedPlan->duration_days)
                : null,
            'joined_at' => $existing?->joined_at ?? $now,
            'dues_due' => $price,
            'application' => $application,
            'payment' => $paid ? [
                'method' => $method,
                'status' => $method === 'in_person' ? 'pay_in_person' : 'awaiting_receipt',
                'amount' => Contract::money($price),
                'receipt_url' => null,
                'reference_number' => null,
                'submitted_at' => null,
                'rejection_reason' => null,
            ] : null,
        ];

        $member = DB::transaction(function () use ($existing, $club, $viewer, $fields, $instant): ClubMember {
            if ($existing !== null) {
                $existing->update($fields);
                $member = $existing;
            } else {
                $member = ClubMember::query()->create($fields + [
                    'club_id' => $club->id,
                    'user_id' => $viewer,
                ]);
            }

            MembershipView::log($member, 'requested', $viewer);

            if ($instant) {
                MembershipView::log($member, 'approved', $viewer, 'Open membership');
            }

            return $member;
        });

        return $this->successResponse(
            data: MembershipView::member($member->fresh()),
            code: Response::HTTP_CREATED,
        );
    }

    /**
     * `POST /organizers/community/:id/membership/receipt`
     */
    public function receipt(Request $request, string $id): JsonResponse
    {
        $club = $this->community('community', $id);

        if ($club === null) {
            return $this->noClub('community');
        }

        $viewer = (int) $request->user()->id;
        $member = ClubMember::query()
            ->where('club_id', $club->id)
            ->where('user_id', $viewer)
            ->first();

        if ($member === null || MembershipView::status($member) !== 'pending' || $member->payment === null) {
            return $this->errorResponse(
                message: "There's no membership payment waiting for a receipt.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $payment = $member->payment;

        if (($payment['method'] ?? null) === 'in_person') {
            return $this->errorResponse(
                message: 'You chose to pay in person. A club admin records it when you pay.',
                code: Response::HTTP_CONFLICT,
            );
        }

        if (($payment['status'] ?? null) === 'in_review') {
            return $this->errorResponse(
                message: "Your receipt is already with the club. You'll see here when it's checked.",
                code: Response::HTTP_CONFLICT,
            );
        }

        if (($payment['status'] ?? null) === 'confirmed') {
            return $this->errorResponse(
                message: 'Your payment is already confirmed.',
                code: Response::HTTP_CONFLICT,
            );
        }

        $receipt = $request->input('receipt');
        $reference = trim((string) $request->input('reference_number', ''));
        $errors = [];

        if (! is_string($receipt) || preg_match('/^data:image\/(png|jpeg|webp);base64,/', $receipt) !== 1) {
            $errors['receipt'] = ['Upload a screenshot of your receipt (PNG, JPG or WebP).'];
        } elseif (strlen($receipt) > 1_500_000) {
            $errors['receipt'] = ['That image is too big. Use one under 1 MB.'];
        }

        if ($reference !== '' && preg_match('/^[A-Za-z0-9 -]{4,30}$/', $reference) !== 1) {
            $errors['reference_number'] = ['Use the reference number from the receipt: letters and numbers only.'];
        }

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $label = ($payment['method'] ?? '') === 'gcash' ? 'GCash' : 'Maya';

        DB::transaction(function () use ($member, $payment, $receipt, $reference, $viewer, $label): void {
            $member->update([
                'payment' => array_merge($payment, [
                    'status' => 'in_review',
                    'receipt_url' => $receipt,
                    'reference_number' => $reference !== '' ? $reference : null,
                    'submitted_at' => now()->toIso8601String(),
                    'rejection_reason' => null,
                ]),
            ]);

            MembershipView::log(
                $member,
                'payment_submitted',
                $viewer,
                $reference !== '' ? "{$label} receipt, ref {$reference}" : "{$label} receipt",
            );
        });

        return $this->successResponse(data: MembershipView::member($member->fresh()));
    }

    /**
     * `POST /organizers/:type/:id/leave`
     */
    public function leave(Request $request, string $type, string $id): Response
    {
        $club = $this->community($type, $id);

        if ($club === null) {
            return $this->noClub($type);
        }

        $viewer = (int) $request->user()->id;
        $member = ClubMember::query()
            ->where('club_id', $club->id)
            ->where('user_id', $viewer)
            ->first();

        if ($member === null) {
            return $this->errorResponse(
                message: "You're not a member of this club.",
                code: Response::HTTP_NOT_FOUND,
            );
        }

        if ($member->role === 'owner') {
            return $this->errorResponse(
                message: "The owner can't leave their own club.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        $member->delete();

        return response()->noContent();
    }

    /**
     * `GET /organizers/:type/:id/members?status=&search=`
     */
    public function members(Request $request, string $type, string $id): JsonResponse
    {
        $club = $this->community($type, $id);

        if ($club === null) {
            return $this->noClub($type);
        }

        if (! CommunityView::canManageCommunity($club, (int) $request->user()->id)) {
            return $this->errorResponse(
                message: "Only the club's admins can see the member list.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        $status = trim((string) $request->query('status', ''));
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $order = ['pending' => 0, 'active' => 1, 'suspended' => 2, 'expired' => 3, 'rejected' => 4];

        $members = ClubMember::query()
            ->with(['user.playerProfile', 'plan', 'payments'])
            ->where('club_id', $club->id)
            ->get()
            ->map(fn (ClubMember $member): array => MembershipView::member($member))
            ->filter(fn (array $member): bool => $status === '' || $member['status'] === $status)
            ->filter(fn (array $member): bool => $search === ''
                || str_contains(mb_strtolower($member['player']['name']), $search))
            ->sortBy(fn (array $member): string => sprintf(
                '%02d-%s',
                $order[$member['status']] ?? 9,
                mb_strtolower($member['player']['name']),
            ))
            ->values()
            ->all();

        return $this->successResponse(data: $members);
    }

    /**
     * `GET /organizers/:type/:id/members/:member`
     */
    public function member(Request $request, string $type, string $id, int $member): JsonResponse
    {
        $club = $this->community($type, $id);

        if ($club === null) {
            return $this->noClub($type);
        }

        if (! CommunityView::canManageCommunity($club, (int) $request->user()->id)) {
            return $this->errorResponse(
                message: 'Not allowed.',
                code: Response::HTTP_FORBIDDEN,
            );
        }

        $row = ClubMember::query()
            ->with(['user.playerProfile', 'plan', 'payments', 'events.actor'])
            ->where('club_id', $club->id)
            ->find($member);

        if ($row === null) {
            return $this->errorResponse(message: 'Member not found.', code: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(data: MembershipView::detail($row));
    }

    /**
     * `POST /organizers/:type/:id/members/:member/dues`
     */
    public function dues(Request $request, string $type, string $id, int $member): JsonResponse
    {
        $club = $this->community($type, $id);

        if ($club === null) {
            return $this->noClub($type);
        }

        $viewer = (int) $request->user()->id;

        if (! CommunityView::canManageCommunity($club, $viewer)) {
            return $this->errorResponse(
                message: "Your role can't record payments.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        $row = ClubMember::query()
            ->with(['user.playerProfile', 'plan', 'payments', 'events.actor'])
            ->where('club_id', $club->id)
            ->find($member);

        if ($row === null) {
            return $this->errorResponse(message: 'Member not found.', code: Response::HTTP_NOT_FOUND);
        }

        $owed = max((float) $row->dues_due - MembershipView::paid($row), 0);

        if ($owed <= 0) {
            return $this->errorResponse(message: 'No dues are owed.', code: Response::HTTP_CONFLICT);
        }

        $amount = $request->input('amount');
        $method = $request->input('method');
        $allowed = ['cash', 'gcash_at_venue', 'maya_at_venue', 'card_at_venue', 'other'];
        $errors = [];

        if (! is_numeric($amount) || (float) $amount <= 0) {
            $errors['amount'] = ['Enter the amount you collected.'];
        } elseif ((float) $amount > $owed) {
            $errors['amount'] = ['Only ₱'.number_format($owed, 2).' is owed.'];
        }

        if (! is_string($method) || ! in_array($method, $allowed, true)) {
            $errors['method'] = ['Choose how they paid.'];
        }

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $label = str_replace('_', ' ', (string) $method);
        $description = (string) ($row->plan?->name ?? 'Membership').' dues';
        $reference = trim((string) $request->input('reference_number', '')) ?: null;

        DB::transaction(function () use ($row, $club, $viewer, $amount, $method, $description, $label, $reference): void {
            ClubMembershipPayment::query()->create([
                'club_id' => $club->id,
                'club_member_id' => $row->id,
                'payer_id' => $row->user_id,
                'amount' => (float) $amount,
                'method' => Contract::backendPaymentMethod((string) $method),
                'reference_number' => $reference,
                'received_by' => $viewer,
                'received_at' => now(),
                'description' => $description,
            ]);

            MembershipView::log(
                $row,
                'dues_paid',
                $viewer,
                '₱'.number_format((float) $amount, 2, '.', '').' '.$label,
            );

            if (MembershipView::status($row) === 'pending'
                && MembershipView::paid($row) >= (float) $row->dues_due) {
                if ($row->payment !== null) {
                    $row->update(['payment' => array_merge($row->payment, ['status' => 'confirmed'])]);
                }

                MembershipView::activate($row);
                MembershipView::log($row, 'approved', $viewer, 'Dues paid');
            }
        });

        return $this->successResponse(data: MembershipView::detail($row->fresh()));
    }

    /**
     * `POST /organizers/:type/:id/members/:member/:action`
     */
    public function action(Request $request, string $type, string $id, int $member, string $action): JsonResponse
    {
        $club = $this->community($type, $id);

        if ($club === null) {
            return $this->noClub($type);
        }

        $viewer = (int) $request->user()->id;

        if (! CommunityView::canManageCommunity($club, $viewer)) {
            return $this->errorResponse(
                message: "Only the club's admins can manage members.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        $row = ClubMember::query()
            ->with(['user.playerProfile', 'plan', 'payments', 'events.actor'])
            ->where('club_id', $club->id)
            ->find($member);

        if ($row === null) {
            return $this->errorResponse(message: 'Member not found.', code: Response::HTTP_NOT_FOUND);
        }

        $status = MembershipView::status($row);
        $reason = trim((string) $request->input('reason', '')) ?: null;
        $payment = $row->payment;

        switch ($action) {
            case 'approve':
                if ($status !== 'pending') {
                    return $this->conflict('Only pending requests can be approved.');
                }

                if (($payment['status'] ?? null) === 'in_review') {
                    return $this->conflict('Check their receipt first: confirming the payment approves them.');
                }

                if (in_array($payment['status'] ?? null, ['awaiting_receipt', 'rejected'], true)) {
                    return $this->conflict(
                        "They haven't sent a receipt that's been accepted yet. Approve them once it's confirmed.",
                    );
                }

                MembershipView::activate($row);
                MembershipView::log($row, 'approved', $viewer, $reason);

                break;

            case 'confirm-payment':
                if ($status !== 'pending' || ($payment['status'] ?? null) !== 'in_review') {
                    return $this->conflict("There's no receipt waiting to be checked.");
                }

                $owed = max((float) $row->dues_due - MembershipView::paid($row), 0);
                $method = ($payment['method'] ?? '') === 'gcash' ? 'gcash' : 'maya';
                $description = (string) ($row->plan?->name ?? 'Membership').' dues';

                DB::transaction(function () use ($row, $club, $viewer, $owed, $method, $description, $payment, $reason): void {
                    ClubMembershipPayment::query()->create([
                        'club_id' => $club->id,
                        'club_member_id' => $row->id,
                        'payer_id' => $row->user_id,
                        'amount' => $owed,
                        'method' => $method,
                        'reference_number' => $payment['reference_number'] ?? null,
                        'received_by' => $viewer,
                        'received_at' => now(),
                        'description' => $description,
                    ]);

                    $row->update(['payment' => array_merge($payment, ['status' => 'confirmed'])]);
                    MembershipView::log(
                        $row,
                        'payment_confirmed',
                        $viewer,
                        '₱'.number_format($owed, 2, '.', '').' '.($method === 'gcash' ? 'GCash' : 'Maya'),
                    );

                    MembershipView::activate($row);
                    MembershipView::log($row, 'approved', $viewer, $reason);
                });

                break;

            case 'reject-payment':
                if ($status !== 'pending' || ($payment['status'] ?? null) !== 'in_review') {
                    return $this->conflict("There's no receipt waiting to be checked.");
                }

                if ($reason === null) {
                    return $this->invalid(['reason' => ["Say what's wrong, so they can send the right receipt."]]);
                }

                $row->update(['payment' => array_merge($payment, [
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                ])]);
                MembershipView::log($row, 'payment_rejected', $viewer, $reason);

                break;

            case 'reject':
                if ($status !== 'pending') {
                    return $this->conflict('Only pending requests can be turned down.');
                }

                if (MembershipView::paid($row) > 0) {
                    return $this->conflict("They've already paid. Refund the payment before turning them down.");
                }

                $row->update(['status' => 'rejected']);
                MembershipView::log($row, 'rejected', $viewer, $reason);

                break;

            case 'suspend':
                if ($row->role === 'owner') {
                    return $this->errorResponse(message: "The owner can't be suspended.", code: Response::HTTP_FORBIDDEN);
                }

                if (! in_array($status, ['active', 'expired'], true)) {
                    return $this->conflict('Only current members can be suspended.');
                }

                if ($reason === null) {
                    return $this->invalid(['reason' => ['Add a reason. The member sees it.']]);
                }

                $row->update(['status' => 'suspended']);
                MembershipView::log($row, 'suspended', $viewer, $reason);

                break;

            case 'reactivate':
                if ($status !== 'suspended') {
                    return $this->conflict('Only suspended members can be reactivated.');
                }

                $row->update(['status' => 'active']);
                MembershipView::log($row, 'reactivated', $viewer, $reason);

                break;

            case 'renew':
                if (! in_array($status, ['active', 'expired'], true)) {
                    return $this->conflict('Only current or expired members can be renewed.');
                }

                if ($row->plan?->duration_days === null) {
                    return $this->conflict("This plan doesn't expire, so there's nothing to renew.");
                }

                $from = $row->expires_at !== null && $row->expires_at->isFuture()
                    ? $row->expires_at->toImmutable()
                    : CarbonImmutable::now();
                $row->update([
                    'status' => 'active',
                    'expires_at' => $from->addDays((int) $row->plan->duration_days),
                    'dues_due' => (float) $row->dues_due + (float) $row->plan->price,
                ]);
                MembershipView::log($row, 'renewed', $viewer, $reason);

                break;

            case 'make-admin':
            case 'remove-admin':
                $isOwner = ClubMember::query()
                    ->where('club_id', $club->id)
                    ->where('user_id', $viewer)
                    ->where('role', 'owner')
                    ->where('status', 'active')
                    ->exists();

                if (! $isOwner) {
                    return $this->errorResponse(
                        message: 'Only the club owner can choose admins.',
                        code: Response::HTTP_FORBIDDEN,
                    );
                }

                if ($row->role === 'owner') {
                    return $this->errorResponse(
                        message: "The owner's role can't change.",
                        code: Response::HTTP_FORBIDDEN,
                    );
                }

                if ($status !== 'active') {
                    return $this->conflict('Only active members can be admins.');
                }

                $row->update(['role' => $action === 'make-admin' ? 'admin' : 'member']);
                MembershipView::log(
                    $row,
                    'role_changed',
                    $viewer,
                    $action === 'make-admin' ? 'Made admin' : 'No longer admin',
                );

                break;

            default:
                return $this->errorResponse(message: 'Unknown action.', code: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(data: MembershipView::detail($row->fresh()));
    }

    /**
     * `GET /organizers/:type/:id/plans`
     */
    public function plans(Request $request, string $type, string $id): JsonResponse
    {
        $club = $this->community($type, $id);

        if ($club === null) {
            return $this->noClub($type);
        }

        $manage = CommunityView::canManageCommunity($club, (int) $request->user()->id);
        $plans = $club->membershipPlans()
            ->when(! $manage, fn ($query) => $query->where('is_active', true))
            ->get()
            ->map(fn (ClubMembershipPlan $plan): array => MembershipView::plan($plan))
            ->values()
            ->all();

        return $this->successResponse(data: $plans);
    }

    /**
     * `POST /organizers/:type/:id/plans`
     */
    public function storePlan(Request $request, string $type, string $id): JsonResponse
    {
        return $this->savePlan($request, $type, $id, null);
    }

    /**
     * `PATCH /organizers/:type/:id/plans/:plan`
     */
    public function updatePlan(Request $request, string $type, string $id, int $plan): JsonResponse
    {
        return $this->savePlan($request, $type, $id, $plan);
    }

    private function savePlan(Request $request, string $type, string $id, ?int $planId): JsonResponse
    {
        $club = $this->community($type, $id);

        if ($club === null) {
            return $this->noClub($type);
        }

        if (! CommunityView::canManageCommunity($club, (int) $request->user()->id)) {
            return $this->errorResponse(
                message: "Only the club's admins can change membership plans.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        $existing = $planId === null
            ? null
            : $club->membershipPlans()->find($planId);

        if ($planId !== null && $existing === null) {
            return $this->errorResponse(message: 'Plan not found.', code: Response::HTTP_NOT_FOUND);
        }

        $input = $request->all();

        if (isset($input['price']) && is_numeric($input['price'])) {
            $input['price'] = (string) $input['price'];
        }

        $validator = Validator::make($input, [
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'duration_days' => ['nullable', 'in:30,90,180,365'],
            'session_discount_percent' => ['required', 'numeric', 'between:0,100'],
            'perks' => ['nullable', 'array', 'max:8'],
            'perks.*.text' => ['nullable', 'string', 'max:60'],
            'perks.*.included' => ['boolean'],
            'recommended' => ['boolean'],
            'is_active' => ['boolean'],
        ], [
            'name.required' => 'Name the plan, like Monthly or Annual.',
            'price.regex' => 'Enter the price in pesos, or 0 for free.',
            'duration_days.in' => 'Choose 1, 3, 6 or 12 months, or no expiry.',
            'session_discount_percent.between' => 'Use a discount between 0 and 100%.',
            'perks.max' => 'List up to 8 perks.',
            'perks.*.text.max' => 'Keep each perk under 60 characters.',
        ]);

        $errors = $validator->errors()->toArray();
        $name = trim((string) ($input['name'] ?? ''));

        if ($name !== '') {
            $duplicate = $club->membershipPlans()
                ->when($existing !== null, fn ($query) => $query->where('id', '!=', $existing->id))
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->exists();

            if ($duplicate) {
                $errors['name'] = ['Another plan already has this name.'];
            }
        }

        $perks = collect(is_array($input['perks'] ?? null) ? $input['perks'] : [])
            ->filter(fn ($perk): bool => is_array($perk) && trim((string) ($perk['text'] ?? '')) !== '')
            ->map(fn (array $perk): array => [
                'text' => trim((string) $perk['text']),
                'included' => (bool) ($perk['included'] ?? true),
            ])
            ->values()
            ->all();

        if (count($perks) > 8) {
            $errors['perks'] = ['List up to 8 perks.'];
        } elseif (collect($perks)->contains(fn (array $perk): bool => mb_strlen($perk['text']) > 60)) {
            $errors['perks'] = ['Keep each perk under 60 characters.'];
        }

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $active = (bool) ($input['is_active'] ?? false);
        $values = [
            'name' => $name,
            'description' => trim((string) ($input['description'] ?? '')),
            'price' => (float) $input['price'],
            'duration_days' => $input['duration_days'] === null || $input['duration_days'] === ''
                ? null
                : (int) $input['duration_days'],
            'session_discount_percent' => (int) round((float) $input['session_discount_percent']),
            'perks' => $perks,
            'recommended' => (bool) ($input['recommended'] ?? false) && $active,
            'is_active' => $active,
        ];

        $saved = DB::transaction(function () use ($club, $existing, $values): ClubMembershipPlan {
            if ($values['recommended']) {
                $club->membershipPlans()
                    ->when($existing !== null, fn ($query) => $query->where('id', '!=', $existing->id))
                    ->update(['recommended' => false]);
            }

            if ($existing !== null) {
                $existing->update($values);

                return $existing;
            }

            return $club->membershipPlans()->create($values + [
                'currency' => Contract::CURRENCY,
            ]);
        });

        return $this->successResponse(
            data: MembershipView::plan($saved->fresh()),
            code: $existing === null ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function applicationErrors(Request $request): array
    {
        $application = $request->input('application');

        if (! is_array($application)) {
            return ['application' => ['Fill in the join form.']];
        }

        $validator = Validator::make($application, [
            'full_name' => ['required', 'string', 'min:3'],
            'email' => ['required', 'email'],
            'phone' => ['required', 'string', 'regex:/^(\+?63|0)9\d{9}$/'],
            'birth_date' => ['required', 'date_format:Y-m-d'],
            'gender' => ['required', 'in:female,male,other,prefer_not_to_say'],
            'emergency_contact_name' => ['required', 'string'],
            'emergency_contact_phone' => ['required', 'string', 'regex:/^(\+?63|0)9\d{9}$/', 'different:phone'],
            'skill_level' => ['required', 'in:beginner,novice,intermediate,advanced,pro'],
            'dominant_hand' => ['required', 'in:right,left,both'],
            'play_focus' => ['required', 'in:social,fitness,competitive,learning'],
            'preferred_times' => ['required', 'array', 'min:1'],
            'preferred_times.*' => ['in:morning,midday,evening,weekends'],
            'signature' => ['required', 'array'],
            'accept_waiver' => ['accepted'],
        ], [
            'full_name.required' => 'Enter your full name.',
            'full_name.min' => 'Enter your full name.',
            'phone.regex' => 'Enter a mobile number, like 09171234567.',
            'emergency_contact_phone.regex' => 'Enter their mobile number, like 09171234567.',
            'emergency_contact_phone.different' => "Use someone else's number, not your own.",
            'preferred_times.required' => 'Choose at least one time you usually play.',
            'preferred_times.min' => 'Choose at least one time you usually play.',
            'accept_waiver.accepted' => "Agree to the club's membership agreement to join.",
            'signature.required' => 'Sign the agreement.',
        ]);

        $errors = $validator->errors()->toArray();
        $phone = str_replace([' ', '-'], '', (string) ($application['phone'] ?? ''));
        $emergency = str_replace([' ', '-'], '', (string) ($application['emergency_contact_phone'] ?? ''));

        if ($phone !== '' && $emergency !== '' && $phone === $emergency) {
            $errors['emergency_contact_phone'] = ["Use someone else's number, not your own."];
        }

        $birth = $application['birth_date'] ?? null;

        if (is_string($birth) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) === 1) {
            try {
                $age = CarbonImmutable::parse($birth)->diffInYears(CarbonImmutable::now());
                if ($age < 7 || $age > 100) {
                    $errors['birth_date'] = ['Check your date of birth.'];
                }
            } catch (\Throwable) {
                $errors['birth_date'] = ['Enter your date of birth.'];
            }
        }

        $signature = $application['signature'] ?? null;

        if (is_array($signature)) {
            if (($signature['kind'] ?? null) === 'typed') {
                $typed = mb_strtolower(trim((string) ($signature['name'] ?? '')));

                if ($typed === '') {
                    $errors['signature'] = ['Type your full name to sign.'];
                } elseif ($typed !== mb_strtolower(trim((string) ($application['full_name'] ?? '')))) {
                    $errors['signature'] = ['Type your name exactly as above.'];
                }
            } elseif (($signature['kind'] ?? null) === 'drawn') {
                $image = (string) ($signature['image'] ?? '');

                if (preg_match('/^data:image\/png;base64,/', $image) !== 1) {
                    $errors['signature'] = ['Draw your signature.'];
                } elseif (strlen($image) > 400_000) {
                    $errors['signature'] = ['That signature is too detailed. Clear it and sign again.'];
                }
            } else {
                $errors['signature'] = ['Sign the agreement.'];
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanApplication(Request $request): array
    {
        $a = $request->input('application');
        $times = array_values(array_unique(array_map('strval', (array) ($a['preferred_times'] ?? []))));
        $signature = $a['signature'];

        return [
            'full_name' => trim((string) $a['full_name']),
            'email' => trim((string) $a['email']),
            'phone' => trim((string) $a['phone']),
            'birth_date' => (string) $a['birth_date'],
            'gender' => (string) $a['gender'],
            'emergency_contact_name' => trim((string) $a['emergency_contact_name']),
            'emergency_contact_phone' => trim((string) $a['emergency_contact_phone']),
            'skill_level' => (string) $a['skill_level'],
            'dominant_hand' => (string) $a['dominant_hand'],
            'play_focus' => (string) $a['play_focus'],
            'preferred_times' => $times,
            'signature' => $signature['kind'] === 'typed'
                ? ['kind' => 'typed', 'name' => trim((string) $signature['name'])]
                : $signature,
            'waiver_accepted_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return list<string>
     */
    private function payMethods(Club $club): array
    {
        $options = is_array($club->payment_options) ? $club->payment_options : [];

        return array_values(array_filter([
            ($options['in_person'] ?? false) ? 'in_person' : null,
            is_array($options['gcash'] ?? null) ? 'gcash' : null,
            is_array($options['maya'] ?? null) ? 'maya' : null,
        ]));
    }

    private function community(string $type, string $id): ?Club
    {
        if ($type !== 'community' || ! ctype_digit($id)) {
            return null;
        }

        return Club::query()->find((int) $id);
    }

    private function noClub(string $type): JsonResponse
    {
        return $this->errorResponse(
            message: $type === 'venue'
                ? "Venues don't have members. Players join clubs instead."
                : 'Club not found.',
            code: Response::HTTP_NOT_FOUND,
        );
    }

    private function conflict(string $message): JsonResponse
    {
        return $this->errorResponse(message: $message, code: Response::HTTP_CONFLICT);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function invalid(array $errors): JsonResponse
    {
        return $this->errorResponse(
            message: 'The given data was invalid.',
            errors: $errors,
            code: Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
