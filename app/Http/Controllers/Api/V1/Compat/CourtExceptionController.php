<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesClub;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Court;
use App\Models\CourtBlockedSchedule;
use App\Models\Tenant;
use App\Support\Compat\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/clubs/{club}/court-exceptions — one-off closures (holidays, repairs,
 * typhoons) as the SPA's ScheduleException contract.
 */
class CourtExceptionController extends Controller
{
    use ApiResponse, AuthorizesClub;

    public function index(Tenant $club): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        $exceptions = CourtBlockedSchedule::query()
            ->where('tenant_id', $club->id)
            ->with('creator')
            ->orderBy('starts_at')
            ->get();

        return $this->successResponse(
            data: $exceptions->map(fn (CourtBlockedSchedule $exception) => $this->shape($exception))->all()
        );
    }

    public function store(Request $request, Tenant $club): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        $data = $request->validate([
            'court_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $court = Court::where('id', $data['court_id'])->where('tenant_id', $club->id)->first();

        if (! $court) {
            return $this->errorResponse('Court not found in this club.', code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $exception = CourtBlockedSchedule::create([
            'court_id' => $court->id,
            'tenant_id' => $club->id,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'reason' => $data['reason'],
            'created_by' => $request->user()->id,
        ]);

        return $this->successResponse(
            data: $this->shape($exception->load('creator')),
            message: 'Closure created.',
            code: Response::HTTP_CREATED
        );
    }

    public function destroy(Tenant $club, CourtBlockedSchedule $exception): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $exception->tenant_id)) {
            return $this->errorResponse('Closure not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        $exception->delete();

        return $this->successResponse(message: 'Closure deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(CourtBlockedSchedule $exception): array
    {
        return [
            'id' => (string) $exception->id,
            'court_id' => (string) $exception->court_id,
            'starts_at' => $exception->starts_at->toIso8601String(),
            'ends_at' => $exception->ends_at->toIso8601String(),
            'exception_type' => 'closure',
            'reason' => (string) ($exception->reason ?? ''),
            'created_by' => Contract::person($exception->creator)
                ?? ['id' => '0', 'name' => 'System'],
            'created_at' => $exception->created_at->toIso8601String(),
        ];
    }
}
