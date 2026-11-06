<?php

namespace App\Http\Controllers\Api\V1\Players;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Requests\Uploads\UploadAvatarRequest;
use App\Http\Resources\UserResource;
use App\Http\Traits\ApiResponse;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    use ApiResponse;

    /**
     * Show player personal profile.
     */
    public function show(Request $request): JsonResponse
    {
        return $this->successResponse(
            data: new UserResource($request->user()),
            message: 'Player profile retrieved.'
        );
    }

    /**
     * Update player personal profile.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return $this->successResponse(
            data: new UserResource($user->fresh()),
            message: 'Player profile updated.'
        );
    }

    /**
     * Upload and update player avatar.
     */
    public function uploadAvatar(UploadAvatarRequest $request, FileUploadService $fileUploadService): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar) {
            $fileUploadService->deleteFromUrl($user->avatar);
        }

        $path = $fileUploadService->upload($request->file('avatar'), 'avatars');
        $user->update(['avatar' => $fileUploadService->url($path)]);

        return $this->successResponse(
            data: new UserResource($user->fresh()),
            message: 'Avatar uploaded successfully.'
        );
    }
}
