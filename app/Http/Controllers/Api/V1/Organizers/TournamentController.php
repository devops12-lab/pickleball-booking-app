<?php

namespace App\Http\Controllers\Api\V1\Organizers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tournaments\StoreTournamentRequest;
use App\Http\Requests\Tournaments\UpdateTournamentRequest;
use App\Http\Resources\TournamentResource;
use App\Http\Traits\ApiResponse;
use App\Models\Tenant;
use App\Models\Tournament;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TournamentController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext
    ) {}

    /**
     * List tournaments organized by user.
     */
    public function index(Request $request): JsonResponse
    {
        $tournaments = Tournament::with(['tenant', 'categories', 'participants'])
            ->where('organizer_id', $request->user()->id)
            ->latest('starts_at')
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse(
            data: TournamentResource::collection($tournaments),
            message: 'Organized tournaments retrieved.',
            meta: [
                'current_page' => $tournaments->currentPage(),
                'total' => $tournaments->total(),
            ]
        );
    }

    /**
     * Create a new tournament.
     */
    public function store(StoreTournamentRequest $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->tenantContext->getTenantId() ?? $request->input('tenant_id');

        if (! $tenantId) {
            $tenant = Tenant::first();
            $tenantId = $tenant?->id ?? 1;
        }

        $validated = $request->validated();

        $slug = Str::slug($validated['name']);
        if (Tournament::where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(4));
        }

        $tournament = Tournament::create(array_merge($validated, [
            'tenant_id' => $tenantId,
            'organizer_id' => $user->id,
            'slug' => $slug,
            'status' => 'upcoming',
        ]));

        return $this->successResponse(
            data: new TournamentResource($tournament),
            message: 'Tournament created.',
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Show tournament details.
     */
    public function show(Request $request, Tournament $tournament): JsonResponse
    {
        if ($tournament->organizer_id !== $request->user()->id && ! $request->user()->isSystemAdmin()) {
            return $this->errorResponse('Unauthorized tournament access.', code: Response::HTTP_FORBIDDEN);
        }

        return $this->successResponse(
            data: new TournamentResource($tournament->load(['tenant', 'categories', 'matches'])),
            message: 'Tournament details retrieved.'
        );
    }

    /**
     * Update tournament details.
     */
    public function update(UpdateTournamentRequest $request, Tournament $tournament): JsonResponse
    {
        if ($tournament->organizer_id !== $request->user()->id && ! $request->user()->isSystemAdmin()) {
            return $this->errorResponse('Unauthorized tournament access.', code: Response::HTTP_FORBIDDEN);
        }

        $tournament->update($request->validated());

        return $this->successResponse(
            data: new TournamentResource($tournament),
            message: 'Tournament updated.'
        );
    }

    /**
     * Delete tournament.
     */
    public function destroy(Request $request, Tournament $tournament): JsonResponse
    {
        if ($tournament->organizer_id !== $request->user()->id && ! $request->user()->isSystemAdmin()) {
            return $this->errorResponse('Unauthorized tournament access.', code: Response::HTTP_FORBIDDEN);
        }

        $tournament->delete();

        return $this->successResponse(
            message: 'Tournament deleted successfully.'
        );
    }
}
