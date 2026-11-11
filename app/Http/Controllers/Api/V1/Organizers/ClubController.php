<?php

namespace App\Http\Controllers\Api\V1\Organizers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clubs\StoreClubRequest;
use App\Http\Requests\Clubs\UpdateClubRequest;
use App\Http\Resources\ClubResource;
use App\Http\Traits\ApiResponse;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ClubController extends Controller
{
    use ApiResponse;

    /**
     * List clubs managed by the organizer.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $clubs = Club::whereHas('members', function ($q) use ($userId): void {
            $q->where('user_id', $userId)->whereIn('role', ['manager', 'organizer'])->where('status', 'active');
        })->withCount('members')->paginate($request->integer('per_page', 15));

        return $this->successResponse(
            data: ClubResource::collection($clubs),
            message: 'Managed clubs retrieved.',
            meta: [
                'current_page' => $clubs->currentPage(),
                'total' => $clubs->total(),
            ]
        );
    }

    /**
     * Create a new club.
     */
    public function store(StoreClubRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $slug = Str::slug($validated['name']);
        if (Club::where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(4));
        }

        $club = Club::create(array_merge($validated, [
            'creator_id' => $user->id,
            'slug' => $slug,
        ]));

        ClubMember::create([
            'club_id' => $club->id,
            'user_id' => $user->id,
            'role' => 'manager',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $this->successResponse(
            data: new ClubResource($club),
            message: 'Club created successfully.',
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Show club details.
     */
    public function show(Request $request, Club $club): JsonResponse
    {
        $this->authorizeOrganizer($request->user(), $club);

        return $this->successResponse(
            data: new ClubResource($club->loadCount('members')),
            message: 'Club details retrieved.'
        );
    }

    /**
     * Update club details.
     */
    public function update(UpdateClubRequest $request, Club $club): JsonResponse
    {
        $this->authorizeOrganizer($request->user(), $club);

        $club->update($request->validated());

        return $this->successResponse(
            data: new ClubResource($club),
            message: 'Club updated successfully.'
        );
    }

    /**
     * Delete club.
     */
    public function destroy(Request $request, Club $club): JsonResponse
    {
        if ($club->creator_id !== $request->user()->id && ! $request->user()->isSystemAdmin()) {
            return $this->errorResponse('Unauthorized: Only club creator can delete this club.', code: Response::HTTP_FORBIDDEN);
        }

        $club->delete();

        return $this->successResponse(
            message: 'Club deleted successfully.'
        );
    }

    /**
     * View club membership roster.
     */
    public function members(Request $request, Club $club): JsonResponse
    {
        $this->authorizeOrganizer($request->user(), $club);

        $members = ClubMember::with('user:id,name,email,avatar')
            ->where('club_id', $club->id)
            ->get();

        return $this->successResponse(
            data: $members,
            message: 'Club members roster retrieved.'
        );
    }

    /**
     * Approve a pending member into the club.
     */
    public function approveMember(Request $request, Club $club, User $player): JsonResponse
    {
        $this->authorizeOrganizer($request->user(), $club);

        $membership = ClubMember::where('club_id', $club->id)
            ->where('user_id', $player->id)
            ->firstOrFail();

        $membership->update(['status' => 'active']);

        return $this->successResponse(
            data: $membership,
            message: "Member {$player->name} approved into {$club->name}."
        );
    }

    protected function authorizeOrganizer(User $user, Club $club): void
    {
        if ($user->isSystemAdmin()) {
            return;
        }

        $isOrganizer = ClubMember::where('club_id', $club->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['manager', 'organizer'])
            ->where('status', 'active')
            ->exists();

        if (! $isOrganizer) {
            abort(403, 'Unauthorized: You are not an organizer of this club.');
        }
    }
}
