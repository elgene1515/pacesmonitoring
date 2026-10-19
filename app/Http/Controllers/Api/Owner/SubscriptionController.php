<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\StoreSubscriptionRequest;
use App\Http\Requests\Owner\UpdateSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    /**
     * An organization's subscription history, newest first.
     */
    public function index(Organization $organization): AnonymousResourceCollection
    {
        return SubscriptionResource::collection($organization->subscriptions()->with('plan')->get());
    }

    /**
     * Record a new subscription period (plan assignment or renewal); it
     * becomes the organization's current subscription.
     */
    public function store(StoreSubscriptionRequest $request, Organization $organization): JsonResponse
    {
        $subscription = $this->subscriptions->assign($organization, $request->subscriptionData(), $request->user());

        return (new SubscriptionResource($subscription))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Change a period's plan, status, dates or notes.
     */
    public function update(UpdateSubscriptionRequest $request, Subscription $subscription): SubscriptionResource
    {
        return new SubscriptionResource($this->subscriptions->update($subscription, $request->subscriptionData()));
    }
}
