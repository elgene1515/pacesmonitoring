<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\ProposeStudentChangeRequest;
use App\Http\Requests\Student\StoreStudentRequest;
use App\Http\Resources\StudentChangeRequestResource;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use App\Services\StudentChangeRequestService;
use App\Services\StudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * A teacher's students: those in the sections they teach (route binding is
 * limited to those). Teachers enroll students and propose changes to their
 * details; admins edit and remove them.
 */
class StudentController extends Controller
{
    public function __construct(
        private readonly StudentService $students,
        private readonly StudentChangeRequestService $changes,
    ) {
    }

    /**
     * The teacher's students (filters: search, section_id, grade_level_id, school_year_id).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return StudentResource::collection(
            $this->students->list($request->user(), $request->only(['search', 'section_id', 'grade_level_id', 'school_year_id']))
        );
    }

    /**
     * Enroll a new student in one of the teacher's sections.
     */
    public function store(StoreStudentRequest $request): JsonResponse
    {
        $student = $this->students->create($request->studentData(), $request->section(), $request->user(), $request->subjectIds());

        return (new StudentResource($student))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * A student with their section (subjects, teachers) and every PACE record.
     */
    public function show(Student $student): StudentResource
    {
        return new StudentResource($this->students->detail($student));
    }

    /**
     * Propose new details for the student; the admin applies or declines them.
     */
    public function proposeChange(ProposeStudentChangeRequest $request, Student $student): JsonResponse
    {
        $change = $this->changes->propose($student, $request->user(), $request->changes());

        return (new StudentChangeRequestResource($change))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
