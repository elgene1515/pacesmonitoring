<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\ConfirmPasswordRequest;
use App\Http\Requests\Owner\StorePlanRequest;
use App\Http\Requests\Owner\UpdatePlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class PlanController extends Controller
{
    /**
     * Every plan (active or not), in display order, with how many subscriptions use it.
     */
    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(
            Plan::query()->withCount(['subscriptions', 'planRequests'])->orderBy('sort_order')->orderBy('id')->get()
        );
    }

    /**
     * Add a plan.
     */
    public function store(StorePlanRequest $request): JsonResponse
    {
        $data = $request->planData();

        if (! array_key_exists('sort_order', $data)) {
            $data['sort_order'] = (int) Plan::query()->max('sort_order') + 1;
        }

        // refresh() picks up the column defaults (is_active, currency) the payload left out.
        $plan = Plan::create($data)->refresh();

        return (new PlanResource($plan->loadCount(['subscriptions', 'planRequests'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Change a plan's name, limits, price or availability. Existing
     * subscriptions keep pointing at it.
     */
    public function update(UpdatePlanRequest $request, Plan $plan): PlanResource
    {
        $plan->fill($request->planData());
        $plan->save();

        return new PlanResource($plan->loadCount(['subscriptions', 'planRequests']));
    }

    /**
     * Delete a plan nobody has ever subscribed to (owner only, password
     * required). A plan with subscription history is retired instead, so
     * those records keep their plan.
     */
    public function destroy(ConfirmPasswordRequest $request, Plan $plan): Response
    {
        if ($plan->subscriptions()->exists()) {
            throw ValidationException::withMessages([
                'plan' => ['This plan has subscriptions on record and cannot be deleted. Retire it instead.'],
            ]);
        }

        if ($plan->slug === config('saas.default_plan')) {
            throw ValidationException::withMessages([
                'plan' => ['This is the plan new schools trial on; change SAAS_DEFAULT_PLAN before deleting it.'],
            ]);
        }

        $plan->planRequests()->delete();
        $plan->delete();

        return response()->noContent();
    }
}
