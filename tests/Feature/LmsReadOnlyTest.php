<?php

declare(strict_types=1);

use App\Exceptions\LmsWriteException;
use App\Models\Lms\Department;
use App\Models\Lms\Designation;
use App\Models\Lms\Guardian;
use App\Models\Lms\Role;
use App\Models\Lms\Staff;
use App\Models\Lms\Student;
use App\Models\Lms\User;

it('allows reading from LMS models', function () {
    // LMS data is live and may drift between backups / restores. Use minimum
    // floors tied to `rules/PLAN.md` acceptance criteria (≥20 staff records)
    // so the suite tolerates routine LMS row-count changes.
    expect(Staff::count())->toBeGreaterThanOrEqual(20);
    expect(User::count())->toBeGreaterThanOrEqual(20);
    expect(Designation::count())->toBeGreaterThanOrEqual(1);
    expect(Department::count())->toBeGreaterThanOrEqual(1);
    expect(Role::count())->toBeGreaterThanOrEqual(5);
});

it('blocks save() on an LMS model', function () {
    $staff = Staff::first();
    $staff->full_name = 'Modified Name';

    expect(fn () => $staff->save())
        ->toThrow(LmsWriteException::class);
});

it('blocks update() on an LMS model', function () {
    $staff = Staff::first();

    expect(fn () => $staff->update(['full_name' => 'foo']))
        ->toThrow(LmsWriteException::class);
});

it('blocks delete() on an LMS model', function () {
    $staff = Staff::first();

    expect(fn () => $staff->delete())
        ->toThrow(LmsWriteException::class);
});

it('blocks the saving event on an LMS model', function () {
    expect(fn () => Staff::create([
        'user_id' => 1,
        'full_name' => 'Test Staff',
        'designation_id' => 1,
        'department_id' => 1,
    ]))
        ->toThrow(LmsWriteException::class);
});

it('blocks forceDelete() on an LMS model', function () {
    $staff = Staff::first();

    expect(fn () => $staff->forceDelete())
        ->toThrow(LmsWriteException::class);
});

it('blocks save() on another LMS model (Designation)', function () {
    $designation = Designation::first();
    $designation->designation_name = 'Modified Designation';

    expect(fn () => $designation->save())
        ->toThrow(LmsWriteException::class);
});

it('blocks update() on another LMS model (Designation)', function () {
    $designation = Designation::first();

    expect(fn () => $designation->update(['designation_name' => 'bar']))
        ->toThrow(LmsWriteException::class);
});

it('blocks delete() on another LMS model (Designation)', function () {
    $designation = Designation::first();

    expect(fn () => $designation->delete())
        ->toThrow(LmsWriteException::class);
});

/*
 * Slice 11 widened the permitted LMS read surface to student and parent
 * tables. The guarantee has to hold for them exactly as it does for staff —
 * these are the tables a bug would corrupt a school's enrolment records
 * through, so "it is inherited from ReadOnlyModel" is asserted rather than
 * assumed.
 */

it('blocks writes to students', function (string $method) {
    $student = Student::first();

    expect(fn () => match ($method) {
        'save' => tap($student, fn ($s) => $s->full_name = 'Renamed')->save(),
        'update' => $student->update(['full_name' => 'Renamed']),
        'delete' => $student->delete(),
        'forceDelete' => $student->forceDelete(),
    })->toThrow(LmsWriteException::class);
})->with(['save', 'update', 'delete', 'forceDelete']);

it('blocks creating a student outright', function () {
    expect(fn () => Student::create(['full_name' => 'Invented Pupil']))
        ->toThrow(LmsWriteException::class);
});

it('blocks writes to guardians', function (string $method) {
    $guardian = Guardian::first();

    expect(fn () => match ($method) {
        'save' => tap($guardian, fn ($g) => $g->guardians_name = 'Renamed')->save(),
        'update' => $guardian->update(['guardians_name' => 'Renamed']),
        'delete' => $guardian->delete(),
    })->toThrow(LmsWriteException::class);
})->with(['save', 'update', 'delete']);

it('still reads students and guardians', function () {
    // Floors, not exact counts — LMS data drifts between backups, same
    // reasoning as the staff assertions above.
    expect(Student::count())->toBeGreaterThanOrEqual(1)
        ->and(Guardian::count())->toBeGreaterThanOrEqual(1);
});

it('resolves a student to the guardian who pays for them', function () {
    $student = Student::whereNotNull('parent_id')->first();

    expect($student?->guardian()?->first())->not->toBeNull();
});
