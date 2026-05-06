<?php

declare(strict_types=1);

use App\Exports\EmployeeBulkEditExport;
use App\Imports\EmployeeBulkEditImport;
use App\Models\Pas\AuditLog;
use App\Models\Pas\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

function authBulkImportAs(string $role): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

it('renders the import index for super-admin', function () {
    $user = authBulkImportAs('super-admin');

    $this->actingAs($user)
        ->get('/admin/employees/import')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->component('admin/employees/import/index'),
        );
});

it('forbids the import index for non-super-admin roles', function (string $role) {
    $user = authBulkImportAs($role);

    $this->actingAs($user)
        ->get('/admin/employees/import')
        ->assertForbidden();
})->with(['payroll-officer', 'hr', 'auditor', 'employee']);

it('downloads the template via Excel::fake', function () {
    Excel::fake();
    $user = authBulkImportAs('super-admin');
    EmployeeProfile::factory()->count(3)->create(['is_active' => true]);

    $this->actingAs($user)
        ->get('/admin/employees/import/template')
        ->assertOk();

    Excel::assertDownloaded(
        'employees-bulk-edit-template.xlsx',
        fn ($export): bool => $export instanceof EmployeeBulkEditExport,
    );
});

it('preview parses an upload, validates per row, and stashes results in the session', function () {
    $user = authBulkImportAs('super-admin');
    $existing = EmployeeProfile::factory()->create([
        'lms_staff_id' => 12345,
        'is_active' => true,
        'basic_salary_centavos' => 5_000_000,
        'employment_classification' => 'regular',
    ]);

    // Build an in-memory CSV — Excel::import understands CSV via mimes.
    // (Maatwebsite/Excel skips fully-blank rows, so we don't include one;
    // the missing-key error path is exercised in the unit-style import test.)
    $csv = "lms_staff_id,full_name (read-only),employment_classification,pay_frequency,basic_salary_centavos,tax_status,date_hired,date_terminated,is_active\n";
    $csv .= "{$existing->lms_staff_id},Whoever,probationary,,7000000,,,,\n"; // change classification + salary
    $csv .= "999999,Ghost,,,,,,,\n";                                          // no profile → error

    $upload = UploadedFile::fake()->createWithContent('bulk.csv', $csv);

    $this->actingAs($user)
        ->post('/admin/employees/import/preview', ['file' => $upload])
        ->assertRedirect('/admin/employees/import')
        ->assertSessionHas('employee_bulk_import.token')
        ->assertSessionHas('employee_bulk_import.parsed');

    $parsed = session('employee_bulk_import.parsed');
    expect($parsed)->toHaveCount(2);

    // Row 1: existing profile, two changes, no errors
    expect($parsed[0]['lms_staff_id'])->toBe($existing->lms_staff_id)
        ->and($parsed[0]['errors'])->toBe([])
        ->and($parsed[0]['changes'])->toHaveKeys(['employment_classification', 'basic_salary_centavos']);

    // Row 2: ghost staff
    expect($parsed[1]['errors'])->not->toBeEmpty();
});

it('the import service surfaces a missing-key error when the cell is blank', function () {
    $import = new EmployeeBulkEditImport;
    $import->collection(collect([
        [
            'lms_staff_id' => null, // missing key
            'full_name_read_only' => null,
            'employment_classification' => 'regular',
            'pay_frequency' => null,
            'basic_salary_centavos' => null,
            'tax_status' => null,
            'date_hired' => null,
            'date_terminated' => null,
            'is_active' => null,
        ],
    ]));

    $parsed = $import->parsed();
    expect($parsed)->toHaveCount(1)
        ->and($parsed[0]['errors'])->not->toBeEmpty()
        ->and($parsed[0]['errors'][0])->toContain('lms_staff_id is required');
});

it('confirm applies non-error rows in a transaction and writes a composite audit row', function () {
    $user = authBulkImportAs('super-admin');
    $a = EmployeeProfile::factory()->create([
        'lms_staff_id' => 1001,
        'employment_classification' => 'regular',
        'basic_salary_centavos' => 5_000_000,
    ]);
    $b = EmployeeProfile::factory()->create([
        'lms_staff_id' => 1002,
        'employment_classification' => 'regular',
        'basic_salary_centavos' => 4_000_000,
    ]);

    $token = (string) Str::uuid();
    session([
        'employee_bulk_import.token' => $token,
        'employee_bulk_import.parsed' => [
            [
                'row_number' => 2,
                'lms_staff_id' => $a->lms_staff_id,
                'profile_exists' => true,
                'full_name' => 'A',
                'changes' => [
                    'employment_classification' => ['from' => 'regular', 'to' => 'probationary'],
                ],
                'errors' => [],
            ],
            [
                'row_number' => 3,
                'lms_staff_id' => $b->lms_staff_id,
                'profile_exists' => true,
                'full_name' => 'B',
                'changes' => [
                    'basic_salary_centavos' => ['from' => 4_000_000, 'to' => 6_000_000],
                ],
                'errors' => [],
            ],
            // Error row — must be skipped on confirm
            [
                'row_number' => 4,
                'lms_staff_id' => null,
                'profile_exists' => false,
                'full_name' => null,
                'changes' => [],
                'errors' => ['lms_staff_id is required.'],
            ],
        ],
        'employee_bulk_import.source_filename' => 'bulk.csv',
    ]);

    $this->actingAs($user)
        ->post('/admin/employees/import/confirm/'.$token)
        ->assertRedirect('/employees')
        ->assertSessionHas('success');

    expect($a->fresh()->employment_classification)->toBe('probationary');
    expect($b->fresh()->basic_salary_centavos)->toBe(6_000_000);

    $audit = AuditLog::query()
        ->where('action', 'employees.bulk_imported')
        ->latest('id')
        ->first();
    expect($audit)->not->toBeNull()
        ->and($audit->after['profiles_touched'])->toBe(2)
        ->and(count($audit->after['changeset']))->toBe(2);
});

it('confirm rejects a stale token', function () {
    $user = authBulkImportAs('super-admin');
    session([
        'employee_bulk_import.token' => 'real-token',
        'employee_bulk_import.parsed' => [],
    ]);

    $this->actingAs($user)
        ->post('/admin/employees/import/confirm/wrong-token')
        ->assertRedirect('/admin/employees/import')
        ->assertSessionHasErrors('token');
});

it('preview rejects non-spreadsheet uploads', function () {
    $user = authBulkImportAs('super-admin');
    $upload = UploadedFile::fake()->create('not-a-spreadsheet.txt', 10, 'text/plain');

    $this->actingAs($user)
        ->post('/admin/employees/import/preview', ['file' => $upload])
        ->assertSessionHasErrors('file');
});

it('the import service skips no-op rows (no changes, no errors)', function () {
    $existing = EmployeeProfile::factory()->create([
        'lms_staff_id' => 7777,
        'basic_salary_centavos' => 3_000_000,
        'employment_classification' => 'regular',
    ]);

    $import = new EmployeeBulkEditImport;
    $import->collection(collect([
        // exact same values — no diff, no errors
        [
            'lms_staff_id' => $existing->lms_staff_id,
            'full_name_read_only' => 'X',
            'employment_classification' => 'regular',
            'pay_frequency' => null,
            'basic_salary_centavos' => 3_000_000,
            'tax_status' => null,
            'date_hired' => null,
            'date_terminated' => null,
            'is_active' => null,
        ],
    ]));

    $parsed = $import->parsed();
    expect($parsed)->toHaveCount(1)
        ->and($parsed[0]['errors'])->toBe([])
        ->and($parsed[0]['changes'])->toBe([]);
});
