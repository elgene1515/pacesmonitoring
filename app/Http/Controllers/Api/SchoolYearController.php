<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SchoolYear\StoreSchoolYearRequest;
use App\Http\Requests\SchoolYear\UpdateSchoolYearRequest;
use App\Http\Resources\SchoolYearResource;
use App\Models\SchoolYear;
use App\Services\SchoolYearService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * School years belong to the organization: admins register and edit them
 * (and set the organization's default), teachers see the same list and pick
 * their own current year. Route binding is organization-scoped, so another
 * organization's years answer 404.
 */
class SchoolYearController extends Controller
{
    public function __construct(private readonly SchoolYearService $schoolYears)
    {
    }

    /**
     * The organization's school years, newest first, with record counts (the
     * teacher's own, or organization-wide for an admin).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return SchoolYearResource::collection(
            $this->schoolYears->listFor($request->user())
        );
    }

    /**
     * Admin: register a school year. The organization's first one becomes its default.
     */
    public function store(StoreSchoolYearRequest $request): JsonResponse
    {
        $year = $this->schoolYears->create($request->user(), $request->schoolYearData());

        return (new SchoolYearResource($year))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * A single school year with its record counts.
     */
    public function show(Request $request, SchoolYear $schoolYear): SchoolYearResource
    {
        return new SchoolYearResource($this->schoolYears->detail($schoolYear, $request->user()));
    }

    /**
     * Admin: change a school year's label, dates or default flag.
     */
    public function update(UpdateSchoolYearRequest $request, SchoolYear $schoolYear): SchoolYearResource
    {
        return new SchoolYearResource(
            $this->schoolYears->update($schoolYear, $request->schoolYearData(), $request->user())
        );
    }

    /**
     * Teacher: make this school year the one they are currently working in.
     */
    public function select(Request $request, SchoolYear $schoolYear): SchoolYearResource
    {
        return new SchoolYearResource(
            $this->schoolYears->selectCurrent($request->user(), $schoolYear)
        );
    }
}
