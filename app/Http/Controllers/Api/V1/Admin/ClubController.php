<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreClubRequest;
use App\Http\Requests\Admin\UpdateClubRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Club;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ClubController extends Controller
{
    use ApiResponse;

    public function __construct(
        private AuditLogService $audit
    ) {}

    public function index(Request $request): JsonResponse
    {
        $clubs = Club::with(['creator', 'homeVenue'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->withCount('members')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return $this->successResponse(
            data: $clubs,
            meta: [
                'current_page' => $clubs->currentPage(),
                'total' => $clubs->total(),
            ]
        );
    }

    public function store(StoreClubRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $slug = Str::slug($validated['name']);
        if (Club::where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(6));
        }

        $club = Club::create(array_merge($validated, [
            'slug' => $slug,
            'creator_id' => $request->user()->id,
            'status' => $validated['status'] ?? 'active',
        ]));

        $this->audit->log('club.created', $club);

        return $this->successResponse(
            data: $club->load(['creator', 'homeVenue']),
            message: 'Club created by admin.',
            code: Response::HTTP_CREATED
        );
    }

    public function show(Club $club): JsonResponse
    {
        return $this->successResponse(
            data: $club->load(['creator', 'homeVenue', 'members', 'membershipPlans']),
            message: 'Club details retrieved.'
        );
    }

    public function update(UpdateClubRequest $request, Club $club): JsonResponse
    {
        $oldValues = $club->only(array_keys($request->validated()));

        $club->update($request->validated());

        $this->audit->log(
            'club.updated',
            $club,
            oldValues: $oldValues,
            newValues: $request->validated()
        );

        return $this->successResponse(
            data: $club->fresh(['creator', 'homeVenue']),
            message: 'Club updated.'
        );
    }

    public function destroy(Club $club): JsonResponse
    {
        $this->audit->log('club.deleted', $club);

        $club->delete();

        return $this->successResponse(
            message: 'Club deleted.'
        );
    }
}
