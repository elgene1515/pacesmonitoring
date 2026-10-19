<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreStudentRequest;
use App\Http\Requests\Student\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use App\Services\StudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class StudentController extends Controller
{
    public function __construct(private readonly StudentService $students)
    {
    }

    /**
     * The current user's students (filters: search, grade_level_id).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return StudentResource::collection(
            $this->students->list($request->user(), $request->only(['search', 'grade_level_id', 'school_year_id']))
        );
    }

    /**
     * Register a new student.
     */
    public function store(StoreStudentRequest $request): JsonResponse
    {
        $student = $this->students->create($request->studentData(), $request->user());

        return (new StudentResource($student))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * A single student with grade level subjects and all PACE records.
     */
    public function show(Student $student): StudentResource
    {
        return new StudentResource($this->students->detail($student));
    }

    /**
     * Update a student's details.
     */
    public function update(UpdateStudentRequest $request, Student $student): StudentResource
    {
        return new StudentResource(
            $this->students->update($student, $request->studentData())
        );
    }

    /**
     * Remove a student and their PACE records.
     */
    public function destroy(Student $student): Response
    {
        $this->students->delete($student);

        return response()->noContent();
    }
}
