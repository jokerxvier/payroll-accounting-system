<?php

declare(strict_types=1);

use App\Jobs\Payroll\RenderPayslipPdfJob;

it('builds the zip PDF filename as {slug-of-name}-{staff_number}.pdf', function (
    ?string $fullName,
    int|string|null $staffNo,
    int $lmsStaffId,
    string $expected,
) {
    expect(RenderPayslipPdfJob::pdfFilename($fullName, $staffNo, $lmsStaffId))->toBe($expected);
})->with([
    'plain name + number' => ['Juan Dela Cruz', 'EMP-001', 42, 'juan-dela-cruz-emp-001.pdf'],
    'integer staff number' => ['Ally Juanta', 14, 42, 'ally-juanta-14.pdf'],
    'accents + spaces slugged' => ['José Someñe', '00 12', 42, 'jose-somene-00-12.pdf'],
    'missing staff number falls back to lms id' => ['Ana Reyes', null, 777, 'ana-reyes-777.pdf'],
    'blank staff number falls back to lms id' => ['Ana Reyes', '  ', 777, 'ana-reyes-777.pdf'],
    'missing name falls back to employee' => [null, 'EMP-9', 5, 'employee-emp-9.pdf'],
    'both missing use safe fallbacks' => [null, null, 5, 'employee-5.pdf'],
]);
