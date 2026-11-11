<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Club;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\Compat\CommunityView;
use App\Support\Compat\OrganizerLedgerView;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `GET /organizers/:type/:id/payments` and `/reports/revenue`.
 * Both organizers read the same persisted ledger; only their own money is
 * included, and refunds reduce the revenue totals without hiding the entries.
 */
class OrganizerMoneyController extends Controller
{
    use ApiResponse;

    /**
     * `GET /organizers/:type/:id/payments?from=&to=&kind=`
     */
    public function payments(Request $request, string $type, int $id): JsonResponse
    {
        [$organizer, $error] = $this->organizer($type, $id);

        if ($error !== null) {
            return $error;
        }

        if (! $this->canSeeMoney($request, $type, $id)) {
            return $this->errorResponse(
                message: "Only managers can see the club's payments.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        [$from, $to, $invalid] = $this->range($request);

        if ($invalid !== null) {
            return $invalid;
        }

        $kind = trim((string) $request->query('kind', ''));
        $entries = OrganizerLedgerView::entries($type, $id, $from, $to);

        if ($kind !== '') {
            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => $entry['kind'] === $kind,
            ));
        }

        return $this->successResponse(data: $entries);
    }

    /**
     * `GET /organizers/:type/:id/reports/revenue?from=&to=`
     */
    public function revenue(Request $request, string $type, int $id): JsonResponse
    {
        [$organizer, $error] = $this->organizer($type, $id);

        if ($error !== null) {
            return $error;
        }

        if (! $this->canSeeMoney($request, $type, $id)) {
            return $this->errorResponse(
                message: "Only managers can see the club's revenue.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        [$from, $to, $invalid] = $this->range($request);

        if ($invalid !== null) {
            return $invalid;
        }

        $entries = OrganizerLedgerView::entries($type, $id, $from, $to);

        return $this->successResponse(
            data: OrganizerLedgerView::report($from->toDateString(), $to->toDateString(), $entries),
        );
    }

    /**
     * @return array{0: object|null, 1: JsonResponse|null}
     */
    private function organizer(string $type, int $id): array
    {
        if ($type === 'community') {
            $club = Club::query()->find($id);

            return $club === null
                ? [null, $this->errorResponse(message: 'Club not found.', code: Response::HTTP_NOT_FOUND)]
                : [$club, null];
        }

        if ($type === 'venue') {
            $tenant = Tenant::query()->find($id);

            return $tenant === null
                ? [null, $this->errorResponse(message: 'Club not found.', code: Response::HTTP_NOT_FOUND)]
                : [$tenant, null];
        }

        return [null, $this->errorResponse(message: 'Club not found.', code: Response::HTTP_NOT_FOUND)];
    }

    private function canSeeMoney(Request $request, string $type, int $id): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        if (in_array('super_admin', $user->getAvailableRoles(), true)) {
            return true;
        }

        if ($type === 'community') {
            $club = Club::query()->find($id);

            return $club !== null && CommunityView::canManageCommunity($club, (int) $user->id);
        }

        return TenantUser::query()
            ->where('tenant_id', $id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: JsonResponse|null}
     */
    private function range(Request $request): array
    {
        $today = CarbonImmutable::now('Asia/Manila')->toDateString();
        $fromRaw = trim((string) $request->query('from', $today));
        $toRaw = trim((string) $request->query('to', $today));

        $validator = Validator::make(
            ['from' => $fromRaw, 'to' => $toRaw],
            ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d']],
        );

        $errors = $validator->errors()->toArray();

        if ($errors === []) {
            try {
                $from = CarbonImmutable::parse($fromRaw, 'Asia/Manila');
                $to = CarbonImmutable::parse($toRaw, 'Asia/Manila');

                if ($from->greaterThan($to)) {
                    $errors['to'] = ['The end date must be on or after the start date.'];
                }
            } catch (Throwable) {
                $errors['from'] = ['Enter a valid date.'];
            }
        }

        if ($errors !== []) {
            return [
                CarbonImmutable::now('Asia/Manila'),
                CarbonImmutable::now('Asia/Manila'),
                $this->errorResponse(
                    message: 'The given data was invalid.',
                    errors: $errors,
                    code: Response::HTTP_UNPROCESSABLE_ENTITY,
                ),
            ];
        }

        return [$from, $to, null];
    }
}
