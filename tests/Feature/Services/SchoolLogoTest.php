<?php

declare(strict_types=1);

use App\Models\Pas\School;
use App\Services\SchoolLogo;
use Illuminate\Support\Facades\Storage;

/*
 * The two answers a logo has, and why they differ.
 *
 * `url()` is for HTML. `dataUri()` is for PDFs, and it is not an optimisation:
 * there is no config/dompdf.php, so the vendor default `enable_remote = false`
 * applies and dompdf REFUSES any http(s) image silently — a URL in a PDF
 * renders nothing at all. The base64 form is also the only one that survives
 * the payslip's queued renderer, where the worker may not share a filesystem
 * with the web node.
 *
 * The load-bearing test here is the missing-file one: a logo deleted out from
 * under the app must not take down payroll's PDF generation.
 */

beforeEach(function (): void {
    Storage::fake(SchoolLogo::DISK);

    $this->school = School::query()->where('slug', 'default')->firstOrFail();
});

function logos(): SchoolLogo
{
    return app(SchoolLogo::class);
}

it('answers null for a school with no logo', function (): void {
    expect(logos()->url($this->school))->toBeNull()
        ->and(logos()->dataUri($this->school))->toBeNull();
});

it('answers null for no school at all', function (): void {
    // The payslip job resolves its tenant; a null one must not throw there.
    expect(logos()->url(null))->toBeNull()
        ->and(logos()->dataUri(null))->toBeNull();
});

it('returns a base64 data URI a PDF can actually render', function (): void {
    Storage::disk(SchoolLogo::DISK)->put('schools/1/logo-abc.png', 'PNGBYTES');
    $this->school->forceFill(['logo_path' => 'schools/1/logo-abc.png'])->save();

    $uri = logos()->dataUri($this->school->refresh());

    expect($uri)->toStartWith('data:')
        ->and($uri)->toContain('base64,')
        ->and($uri)->toContain(base64_encode('PNGBYTES'));
});

it('returns null rather than throwing when the file has gone', function (): void {
    // The column points somewhere; the disk does not have it. Payroll must
    // still produce a payslip.
    $this->school->forceFill(['logo_path' => 'schools/1/deleted.png'])->save();

    expect(logos()->dataUri($this->school->refresh()))->toBeNull();
});

it('produces a browser URL that carries the stored path', function (): void {
    Storage::disk(SchoolLogo::DISK)->put('schools/1/logo-abc.png', 'PNGBYTES');
    $this->school->forceFill(['logo_path' => 'schools/1/logo-abc.png'])->save();

    expect(logos()->url($this->school->refresh()))
        ->toContain('schools/1/logo-abc.png');
});
