<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSocialAccountRequest;
use App\Http\Requests\UpdateSocialAccountRequest;
use App\Http\Resources\SocialAccountResource;
use App\Models\SocialAccount;
use App\Services\SocialAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SocialAccountController extends Controller
{
    public function __construct(private SocialAccountService $socialAccountService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SocialAccount::class);

        $accounts = $this->socialAccountService->list($request->integer('per_page', 15));

        return SocialAccountResource::collection($accounts)
            ->response();
    }

    public function show(SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('view', $socialAccount);

        $socialAccount->load('platform');

        return (new SocialAccountResource($socialAccount))
            ->response();
    }

    public function store(StoreSocialAccountRequest $request): JsonResponse
    {
        $this->authorize('create', SocialAccount::class);

        $account = $this->socialAccountService->create($request->validated());

        return (new SocialAccountResource($account->load('platform')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateSocialAccountRequest $request, SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('update', $socialAccount);

        $account = $this->socialAccountService->update($socialAccount, $request->validated());

        return (new SocialAccountResource($account))
            ->response();
    }

    public function destroy(SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('delete', $socialAccount);

        $this->socialAccountService->delete($socialAccount);

        return \App\Http\Resources\BaseResource::success(null);
    }
}
