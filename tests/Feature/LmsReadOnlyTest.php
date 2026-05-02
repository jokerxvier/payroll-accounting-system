<?php

declare(strict_types=1);

use App\Exceptions\LmsWriteException;
use App\Models\Lms\Department;
use App\Models\Lms\Designation;
use App\Models\Lms\Role;
use App\Models\Lms\Staff;
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
