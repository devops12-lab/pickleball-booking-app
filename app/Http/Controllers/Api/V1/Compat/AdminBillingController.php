<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesPlatformAdmin;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Support\Compat\AdminView;
use App\Support\Compat\BillingView;
use App\Support\Compat\Contract;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Super Admin's billing desk: plans, every venue's subscription, invoices
 * and revenue. "club" means a venue tenant, as elsewhere in the compat API.
 */
class AdminBillingController extends Controller
{
    use ApiResponse, AuthorizesPlatformAdmin;

    private const METHODS = ['gcash', 'maya', 'bank_transfer', 'cash'];

    private const METHOD_LABELS = [
        'gcash' => 'GCash',
        'maya' => 'Maya',
        'bank_transfer' => 'bank transfer',
        'cash' => 'cash',
    ];

    public function plans(): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $plans = SubscriptionPlan::query()
            ->withCount('subscriptions')
            ->orderBy('id')
            ->get()
            ->map(fn (SubscriptionPlan $plan): array => BillingView::plan($plan))
            ->values()
            ->all();

        return $this->successResponse(data: $plans);
    }

    public function storePlan(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $input = $this->planInput($request);

        if ($input['errors'] !== []) {
            return $this->validationError($input['errors']);
        }

        $values = $input['values'];
        $code = $this->uniqueCode($values['name']);

        $plan = SubscriptionPlan::create([
            'name' => $values['name'],
            'slug' => $code,
            'description' => null,
            'price' => $values['price'],
            'billing_interval' => 'monthly',
            'max_courts' => $values['max_courts'],
            'max_staff' => $values['max_staff'],
            'features' => $values['features'],
            'is_active' => $values['offered'],
        ]);

        AdminView::writeAudit(
            action: 'plan.created',
            summary: 'Created the '.$plan->name.' plan, '.Contract::money($plan->price).' a month',
            type: 'plan',
            id: (int) $plan->id,
            name: (string) $plan->name,
            reason: null,
        );

        return $this->successResponse(
            data: BillingView::plan($plan->loadCount('subscriptions')),
            message: 'Plan created.',
            code: Response::HTTP_CREATED,
        );
    }

    public function updatePlan(Request $request, string $code): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $plan = SubscriptionPlan::query()->where('slug', $code)->first();

        if ($plan === null && ctype_digit($code)) {
            $plan = SubscriptionPlan::query()->find((int) $code);
        }

        if ($plan === null) {
            return $this->errorResponse("This plan doesn't exist.", code: Response::HTTP_NOT_FOUND);
        }

        $input = $this->planInput($request, (int) $plan->id);

        if ($input['errors'] !== []) {
            return $this->validationError($input['errors']);
        }

        $values = $input['values'];

        if ($plan->is_active && ! $values['offered']
            && SubscriptionPlan::query()->where('is_active', true)->where('id', '!=', $plan->id)->doesntExist()) {
            return $this->errorResponse(
                'Keep at least one plan offered, so new venues have a plan to choose.',
                code: Response::HTTP_CONFLICT,
            );
        }

        $before = [
            'name' => (string) $plan->name,
            'price' => (float) $plan->price,
            'max_courts' => $plan->max_courts === null ? null : (int) $plan->max_courts,
            'max_staff' => $plan->max_staff === null ? null : (int) $plan->max_staff,
            'features' => BillingView::featurePayload($plan->features),
            'offered' => (bool) $plan->is_active,
        ];
        $after = [
            'name' => $values['name'],
            'price' => (float) $values['price'],
            'max_courts' => $values['max_courts'],
            'max_staff' => $values['max_staff'],
            'features' => $values['features'],
            'offered' => $values['offered'],
        ];

        if ($before === $after) {
            return $this->successResponse(data: BillingView::plan($plan->loadCount('subscriptions')));
        }

        $changes = [];
        if ($before['name'] !== $after['name']) {
            $changes[] = $before['name'].' → '.$after['name'];
        }
        if ($before['price'] !== $after['price']) {
            $changes[] = Contract::money($before['price']).' → '.Contract::money($after['price']);
        }
        foreach (['max_courts', 'max_staff'] as $key) {
            if ($before[$key] !== $after[$key]) {
                $changes[] = str_replace('max_', '', $key).' limit '
                    .($before[$key] ?? 'none').' → '.($after[$key] ?? 'none');
            }
        }
        foreach (['open_play', 'tournaments', 'reports'] as $key) {
            if ($before['features'][$key] !== $after['features'][$key]) {
                $changes[] = Str::headline($key).' '.($after['features'][$key] ? 'included' : 'removed');
            }
        }
        if ($before['offered'] !== $after['offered']) {
            $changes[] = $after['offered'] ? 'offered again' : 'retired';
        }

        $plan->fill([
            'name' => $values['name'],
            'price' => $values['price'],
            'max_courts' => $values['max_courts'],
            'max_staff' => $values['max_staff'],
            'features' => $values['features'],
            'is_active' => $values['offered'],
        ])->save();

        AdminView::writeAudit(
            action: 'plan.updated',
            summary: 'Changed the '.$plan->name.' plan'.($changes === [] ? '' : ': '.implode(', ', $changes)),
            type: 'plan',
            id: (int) $plan->id,
            name: (string) $plan->name,
            reason: null,
        );

        return $this->successResponse(
            data: BillingView::plan($plan->loadCount('subscriptions')),
            message: 'Plan updated.',
        );
    }

    public function subscriptions(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $status = trim((string) $request->query('status', ''));

        $rows = TenantSubscription::query()
            ->with($this->subscriptionRelations())
            ->get()
            ->filter(fn (TenantSubscription $subscription): bool => $subscription->tenant !== null
                && $subscription->tenant->slug !== 'platform-admin')
            ->map(fn (TenantSubscription $subscription): array => BillingView::subscriptionRow($subscription))
            ->filter(function (array $row) use ($q, $status): bool {
                if ($status !== '' && $row['status'] !== $status) {
                    return false;
                }

                if ($q === '') {
                    return true;
                }

                return collect([
                    $row['club_name'],
                    $row['owner']['name'] ?? '',
                    $row['owner']['email'] ?? '',
                ])->contains(fn (string $value): bool => str_contains(mb_strtolower($value), $q));
            })
            ->values()
            ->all();

        usort($rows, function (array $a, array $b): int {
            $rank = fn (array $row): int => $row['overdue'] ? 0 : ($row['status'] === 'trialing' ? 1 : 2);

            return $rank($a) <=> $rank($b) ?: strcasecmp($a['club_name'], $b['club_name']);
        });

        return $this->successResponse(data: $rows);
    }

    public function subscription(string $subscription): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $row = $this->findSubscription($subscription);

        if ($row === null) {
            return $this->errorResponse("This subscription doesn't exist.", code: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(data: BillingView::subscriptionRow($row));
    }

    public function extendTrial(Request $request, string $subscription): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $row = $this->findSubscription($subscription);

        if ($row === null) {
            return $this->errorResponse("This subscription doesn't exist.", code: Response::HTTP_NOT_FOUND);
        }

        $days = filter_var($request->input('days'), FILTER_VALIDATE_INT);
        $reason = trim((string) $request->input('reason', ''));
        $errors = [];

        if ($days === false || $days < 1 || $days > 30) {
            $errors['days'] = ['Extend by 1 to 30 days.'];
        }
        if (mb_strlen($reason) < 10) {
            $errors['reason'] = ['Say why in a sentence (at least 10 characters).'];
        }
        if ($errors !== []) {
            return $this->validationError($errors);
        }

        $club = (string) ($row->tenant?->name ?? 'This venue');

        if (BillingView::subscriptionStatus($row->status) !== 'trialing') {
            return $this->errorResponse(
                $club." isn't on a trial, so there's nothing to extend.",
                code: Response::HTTP_CONFLICT,
            );
        }
        if ($row->trial_ends_at === null) {
            return $this->errorResponse(
                $club."'s trial starts when it's approved. Approve the venue first.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $now = CarbonImmutable::now();
        $from = $row->trial_ends_at->greaterThan($now)
            ? CarbonImmutable::parse($row->trial_ends_at)
            : $now;

        $row->update(['trial_ends_at' => $from->addDays((int) $days)]);

        AdminView::writeAudit(
            action: 'subscription.trial_extended',
            summary: 'Extended '.$club.'\'s trial by '.$days.' '.($days === 1 ? 'day' : 'days'),
            type: 'venue',
            id: (int) $row->tenant_id,
            name: $club,
            reason: $reason,
            tenantId: (int) $row->tenant_id,
        );

        return $this->successResponse(
            data: BillingView::subscriptionRow($row->fresh()->load($this->subscriptionRelations())),
            message: 'Trial extended.',
        );
    }

    public function changePlan(Request $request, string $subscription): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $row = $this->findSubscription($subscription);

        if ($row === null) {
            return $this->errorResponse("This subscription doesn't exist.", code: Response::HTTP_NOT_FOUND);
        }

        $code = trim((string) $request->input('plan_code', ''));
        $plan = SubscriptionPlan::query()->where('slug', $code)->first();

        if ($plan === null && ctype_digit($code)) {
            $plan = SubscriptionPlan::query()->find((int) $code);
        }

        if ($plan === null || ! $plan->is_active) {
            return $this->validationError(['plan_code' => ["Choose a plan that's offered."]]);
        }

        $club = (string) ($row->tenant?->name ?? 'This venue');

        if ((int) $row->subscription_plan_id === (int) $plan->id) {
            return $this->errorResponse(
                $club.' is already on '.$plan->name.'.',
                code: Response::HTTP_CONFLICT,
            );
        }

        $tenant = $row->tenant;
        $over = [];

        if ($tenant !== null) {
            $courts = $tenant->courts()->count();
            if ($plan->max_courts !== null && $courts > (int) $plan->max_courts) {
                $over[] = $courts.' courts ('.$plan->name.' allows '.$plan->max_courts.')';
            }

            $staff = $tenant->tenantUsers()->where('status', 'active')->count();
            if ($plan->max_staff !== null && $staff > (int) $plan->max_staff) {
                $over[] = $staff.' staff accounts ('.$plan->name.' allows '.$plan->max_staff.')';
            }
        }

        if ($over !== []) {
            return $this->errorResponse(
                $club.' has '.implode(' and ', $over).'. Remove some first, or choose a bigger plan.',
                code: Response::HTTP_CONFLICT,
            );
        }

        $from = (string) ($row->plan?->name ?? 'its old plan');

        DB::transaction(function () use ($row, $plan, $tenant): void {
            $row->update(['subscription_plan_id' => $plan->id]);
            $tenant?->update(['subscription_plan_id' => $plan->id]);
        });

        AdminView::writeAudit(
            action: 'subscription.plan_changed',
            summary: 'Moved '.$club.' from '.$from.' to '.$plan->name,
            type: 'venue',
            id: (int) $row->tenant_id,
            name: $club,
            reason: trim((string) $request->input('reason', '')) ?: null,
            tenantId: (int) $row->tenant_id,
        );

        return $this->successResponse(
            data: BillingView::subscriptionRow($row->fresh()->load($this->subscriptionRelations())),
            message: 'Plan changed.',
        );
    }

    public function invoices(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $status = trim((string) $request->query('status', ''));

        $rows = SubscriptionInvoice::query()
            ->with(['tenant', 'subscription'])
            ->get()
            ->filter(fn (SubscriptionInvoice $invoice): bool => $invoice->tenant !== null
                && $invoice->tenant->slug !== 'platform-admin')
            ->map(fn (SubscriptionInvoice $invoice): array => BillingView::adminInvoice($invoice))
            ->filter(function (array $row) use ($q, $status): bool {
                if ($status !== '' && $row['status'] !== $status) {
                    return false;
                }

                if ($q === '') {
                    return true;
                }

                return str_contains(mb_strtolower($row['club_name']), $q)
                    || str_contains(mb_strtolower($row['invoice_number']), $q);
            })
            ->values()
            ->all();

        usort($rows, function (array $a, array $b): int {
            $rank = fn (array $row): int => match ($row['status']) {
                'overdue' => 0,
                'issued' => 1,
                default => 2,
            };

            return $rank($a) <=> $rank($b) ?: strcmp($b['issued_at'], $a['issued_at']);
        });

        return $this->successResponse(data: $rows);
    }

    public function markPaid(Request $request, string $invoice): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $row = SubscriptionInvoice::query()
            ->with(['tenant', 'subscription'])
            ->find($invoice);

        if ($row === null) {
            return $this->errorResponse("This invoice doesn't exist.", code: Response::HTTP_NOT_FOUND);
        }

        $method = trim((string) $request->input('method', ''));
        $reference = trim((string) $request->input('reference', ''));
        $errors = [];

        if (! in_array($method, self::METHODS, true)) {
            $errors['method'] = ['Choose how it was paid.'];
        }
        if ($method !== 'cash' && mb_strlen($reference) < 4) {
            $errors['reference'] = ['Enter the reference number from the receipt.'];
        }
        if ($errors !== []) {
            return $this->validationError($errors);
        }

        if (! in_array(BillingView::invoiceStatus($row->status), ['issued', 'overdue'], true)) {
            return $this->errorResponse(
                $row->invoice_number.' is '.BillingView::invoiceStatus($row->status).", so it can't be paid.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $club = (string) ($row->tenant?->name ?? 'This venue');
        $subscription = $row->subscription;
        $renewed = false;

        DB::transaction(function () use ($row, $subscription, $method, $reference, $club, &$renewed): void {
            $row->update([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_method' => $method,
                'payment_reference' => $reference !== '' ? $reference : null,
            ]);

            if ($subscription !== null) {
                $stillUnpaid = $subscription->invoices()
                    ->whereIn('status', ['pending', 'failed', 'issued', 'overdue'])
                    ->exists();
                $status = BillingView::subscriptionStatus($subscription->status);

                if (! $stillUnpaid && ! in_array($status, ['active', 'trialing'], true)) {
                    $start = CarbonImmutable::now();
                    $subscription->update([
                        'status' => 'active',
                        'starts_at' => $start,
                        'ends_at' => $start->addDays(30),
                    ]);
                    $renewed = true;
                }
            }

            AdminView::writeAudit(
                action: 'invoice.paid',
                summary: 'Recorded '.Contract::money($row->amount).' '.self::METHOD_LABELS[$method].' payment for '
                    .$row->invoice_number.($renewed ? '; '.$club."'s plan is active again" : ''),
                type: 'venue',
                id: (int) $row->tenant_id,
                name: $club,
                reason: null,
                tenantId: (int) $row->tenant_id,
            );
        });

        return $this->successResponse(
            data: BillingView::adminInvoice($row->fresh(['tenant', 'subscription'])),
            message: 'Payment recorded.',
        );
    }

    public function voidInvoice(Request $request, string $invoice): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $row = SubscriptionInvoice::query()
            ->with(['tenant', 'subscription'])
            ->find($invoice);

        if ($row === null) {
            return $this->errorResponse("This invoice doesn't exist.", code: Response::HTTP_NOT_FOUND);
        }

        $reason = trim((string) $request->input('reason', ''));

        if (mb_strlen($reason) < 10) {
            return $this->validationError([
                'reason' => ['Say why in a sentence (at least 10 characters).'],
            ]);
        }

        if (! in_array(BillingView::invoiceStatus($row->status), ['issued', 'overdue'], true)) {
            return $this->errorResponse(
                $row->invoice_number.' is '.BillingView::invoiceStatus($row->status).", so it can't be voided.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $row->update([
            'status' => 'void',
            'void_reason' => $reason,
        ]);

        $club = (string) ($row->tenant?->name ?? 'This venue');

        AdminView::writeAudit(
            action: 'invoice.voided',
            summary: 'Voided '.$row->invoice_number.' ('.Contract::money($row->amount).')',
            type: 'venue',
            id: (int) $row->tenant_id,
            name: $club,
            reason: $reason,
            tenantId: (int) $row->tenant_id,
        );

        return $this->successResponse(
            data: BillingView::adminInvoice($row->fresh(['tenant', 'subscription'])),
            message: 'Invoice voided.',
        );
    }

    public function revenue(): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        return $this->successResponse(data: BillingView::revenue());
    }

    /**
     * @return list<string>
     */
    private function subscriptionRelations(): array
    {
        return [
            'tenant.tenantUsers.role',
            'tenant.tenantUsers.user',
            'plan',
            'invoices',
        ];
    }

    private function findSubscription(string $id): ?TenantSubscription
    {
        $row = TenantSubscription::query()->with($this->subscriptionRelations())->find($id);

        if ($row === null && ctype_digit($id)) {
            $row = TenantSubscription::query()
                ->with($this->subscriptionRelations())
                ->where('tenant_id', (int) $id)
                ->first();
        }

        return $row;
    }

    /**
     * @return array{errors: array<string, list<string>>, values: array<string, mixed>}
     */
    private function planInput(Request $request, ?int $exceptId = null): array
    {
        $errors = [];
        $name = trim((string) $request->input('name', ''));
        $price = $request->input('price');
        $limits = $request->input('limits', []);
        $features = $request->input('features', []);

        if (mb_strlen($name) < 2) {
            $errors['name'] = ['Give the plan a name venues will recognise.'];
        } elseif (SubscriptionPlan::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists()) {
            $errors['name'] = ['Another plan already has this name.'];
        }

        if (! is_numeric($price) || (float) $price < 0 || (float) $price > 100000) {
            $errors['price'] = ['Enter a monthly price from ₱0 to ₱100,000.'];
        }

        if (! is_array($limits)) {
            $errors['limits'] = ['Enter a limit or leave it empty.'];
            $limits = [];
        }

        [$courtsOk, $courts] = $this->parseLimit($limits['courts'] ?? null);
        if (! $courtsOk) {
            $errors['limits.courts'] = ['Enter 1 to 500, or no limit.'];
        }

        [$staffOk, $staff] = $this->parseLimit($limits['staff'] ?? null);
        if (! $staffOk) {
            $errors['limits.staff'] = ['Enter 1 to 500, or no limit.'];
        }

        if (! is_array($features)) {
            $errors['features'] = ['Choose the features this plan includes.'];
            $features = [];
        }

        $offered = filter_var($request->input('offered', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($offered === null) {
            $errors['offered'] = ['Choose whether venues can select this plan.'];
            $offered = true;
        }

        return [
            'errors' => $errors,
            'values' => [
                'name' => $name,
                'price' => is_numeric($price) ? round((float) $price, 2) : 0,
                'max_courts' => $courts,
                'max_staff' => $staff,
                'features' => BillingView::featurePayload($features),
                'offered' => (bool) $offered,
            ],
        ];
    }

    /**
     * @return array{0: bool, 1: int|null}
     */
    private function parseLimit(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [true, null];
        }

        if (is_int($value) || (is_float($value) && floor($value) === $value)) {
            $number = (int) $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $number = (int) $value;
        } else {
            return [false, null];
        }

        return $number >= 1 && $number <= 500 ? [true, $number] : [false, null];
    }

    private function uniqueCode(string $name): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'plan';
        $code = $base;
        $suffix = 2;

        while (SubscriptionPlan::query()->where('slug', $code)->exists()) {
            $code = $base.'-'.$suffix++;
        }

        return $code;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validationError(array $errors): JsonResponse
    {
        return $this->errorResponse(
            message: 'The given data was invalid.',
            errors: $errors,
            code: Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
