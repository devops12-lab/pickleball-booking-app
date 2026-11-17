<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\FormDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FormDraftController extends Controller
{
    use ApiResponse;

    private const ALLOWED_TYPES = [
        'tournament_registration',
        'booking_request',
        'advertisement_request',
        'club_membership',
    ];

    public function index(Request $request): JsonResponse
    {
        $drafts = FormDraft::where('user_id', $request->user()->id)
            ->when($request->form_type, fn ($q, $t) => $q->where('form_type', $t))
            ->orderByDesc('updated_at')
            ->paginate($request->integer('per_page', 20));

        return $this->successResponse(
            data: $drafts,
            meta: [
                'current_page' => $drafts->currentPage(),
                'total' => $drafts->total(),
            ]
        );
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'form_type' => ['required', 'string', 'in:'.implode(',', self::ALLOWED_TYPES)],
            'draft_data' => ['required', 'array'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ]);

        $draft = FormDraft::create([
            'user_id' => $request->user()->id,
            'tenant_id' => $request->input('tenant_id'),
            'form_type' => $request->input('form_type'),
            'draft_data' => $request->input('draft_data'),
        ]);

        return $this->successResponse(
            data: $draft,
            message: 'Draft saved.',
            code: Response::HTTP_CREATED
        );
    }

    public function show(Request $request, FormDraft $formDraft): JsonResponse
    {
        if ($formDraft->user_id !== $request->user()->id) {
            return $this->errorResponse('Unauthorized.', code: Response::HTTP_FORBIDDEN);
        }

        return $this->successResponse(data: $formDraft);
    }

    public function update(Request $request, FormDraft $formDraft): JsonResponse
    {
        if ($formDraft->user_id !== $request->user()->id) {
            return $this->errorResponse('Unauthorized.', code: Response::HTTP_FORBIDDEN);
        }

        $request->validate([
            'draft_data' => ['required', 'array'],
        ]);

        $formDraft->update([
            'draft_data' => $request->input('draft_data'),
        ]);

        return $this->successResponse(
            data: $formDraft->fresh(),
            message: 'Draft updated.'
        );
    }

    public function destroy(Request $request, FormDraft $formDraft): JsonResponse
    {
        if ($formDraft->user_id !== $request->user()->id) {
            return $this->errorResponse('Unauthorized.', code: Response::HTTP_FORBIDDEN);
        }

        $formDraft->delete();

        return $this->successResponse(message: 'Draft deleted.');
    }
}
