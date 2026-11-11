<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Enums\TenantPaymentMethodType;
use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesClub;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Tenant;
use App\Models\TenantPaymentMethod;
use App\Services\FileUploadService;
use App\Support\Compat\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/clubs/{club}/payment-methods — the channels a venue accepts at
 * checkout (cash at the desk, GCash, Maya, QRPh, bank transfer).
 */
class PaymentMethodController extends Controller
{
    use ApiResponse, AuthorizesClub;

    public function index(Tenant $club): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        $methods = $club->paymentMethods()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->successResponse(
            data: $methods->map(fn (TenantPaymentMethod $method) => Contract::tenantPaymentMethod($method))->all()
        );
    }

    public function store(Request $request, Tenant $club): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        $data = $this->validated($request);

        $method = new TenantPaymentMethod([
            'name' => $data['name'],
            'type' => $data['type'],
            'account_name' => $data['account_name'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? ((int) $club->paymentMethods()->max('sort_order') + 1),
        ]);
        $method->tenant_id = $club->id;

        if ($request->hasFile('qr_image')) {
            $method->qr_image = app(FileUploadService::class)->upload($request->file('qr_image'), 'payment-methods');
        }

        $method->save();

        return $this->successResponse(
            data: Contract::tenantPaymentMethod($method),
            message: 'Payment method added.',
            code: Response::HTTP_CREATED
        );
    }

    public function update(Request $request, Tenant $club, TenantPaymentMethod $paymentMethod): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $paymentMethod->tenant_id)) {
            return $this->errorResponse('Payment method not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        $data = $this->validated($request, partial: true);

        foreach (['name', 'type', 'account_name', 'account_number', 'instructions', 'is_active', 'sort_order'] as $key) {
            if (array_key_exists($key, $data)) {
                $paymentMethod->{$key} = $data[$key];
            }
        }

        if ($request->hasFile('qr_image')) {
            $upload = app(FileUploadService::class);

            if ($paymentMethod->qr_image !== null) {
                $upload->delete($paymentMethod->qr_image);
            }

            $paymentMethod->qr_image = $upload->upload($request->file('qr_image'), 'payment-methods');
        }

        $paymentMethod->save();

        return $this->successResponse(
            data: Contract::tenantPaymentMethod($paymentMethod),
            message: 'Payment method saved.'
        );
    }

    public function destroy(Tenant $club, TenantPaymentMethod $paymentMethod): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $paymentMethod->tenant_id)) {
            return $this->errorResponse('Payment method not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        $paymentMethod->delete();

        return $this->successResponse(message: 'Payment method removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:100'],
            'type' => [$required, 'string', Rule::in(TenantPaymentMethodType::values())],
            'account_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'qr_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ]);
    }
}
