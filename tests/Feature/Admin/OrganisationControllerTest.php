<?php

declare(strict_types=1);

use App\Models\Pas\School;
use App\Models\User;
use App\Services\SchoolLogo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * /admin/organisation — a school's own letterhead.
 *
 * This is the app's FIRST persisted upload. Everything that came before
 * (employee bulk edit, opening balances) parses a spreadsheet and discards it,
 * so the disk choice, the naming scheme and the validation here are precedents
 * rather than conventions being followed — and the refusals below are the part
 * worth getting right.
 *
 * Pinned:
 *  - PNG and JPEG only. An SVG is a script-bearing document; accepting one
 *    from a form and serving it back is stored XSS.
 *  - A blank file input means "keep the current logo", never "clear it".
 *  - Replacing deletes what it replaced, so the disk does not accumulate.
 *  - One school's upload never lands in another's folder.
 */

beforeEach(function (): void {
    Storage::fake(SchoolLogo::DISK);

    $this->school = School::query()->where('slug', 'default')->firstOrFail();
});

function organisationAuthAs(string $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

/** A real 1×1 PNG — `UploadedFile::fake()->image()` needs GD. */
function pngUpload(string $name = 'logo.png'): UploadedFile
{
    return UploadedFile::fake()->image($name, 120, 120);
}

/* ── The gate ───────────────────────────────────────────────────────── */

it('lets a school super admin edit its own identity', function (): void {
    $this->actingAs(organisationAuthAs('super-admin'))
        ->get(route('admin.organisation.edit'))
        ->assertOk();
});

it('refuses an accountant', function (): void {
    // The letterhead is the same trust level as the merchant credentials
    // beside it: both are what the school presents to the outside world.
    $this->actingAs(organisationAuthAs('accountant'))
        ->get(route('admin.organisation.edit'))
        ->assertForbidden();
});

it('lets a platform admin in through the explicit role list', function (): void {
    // A direct hasAnyRole() check bypasses the Gate::before short-circuit, so
    // platform-admin has to be named or the cross-tenant operator is locked
    // out of a screen they reach everywhere else.
    $user = User::factory()->withoutLmsMirror()->create();
    $user->syncRoles(['platform-admin']);

    $this->actingAs($user->fresh())
        ->get(route('admin.organisation.edit'))
        ->assertOk();
});

/* ── Uploading ──────────────────────────────────────────────────────── */

it('stores a logo under the school\'s own folder', function (): void {
    $this->actingAs(organisationAuthAs('super-admin'))
        ->patch(route('admin.organisation.update'), [
            'logo' => pngUpload(),
            'registered_name' => 'St Jude Academy Educational Foundation, Inc.',
            'tin' => '123-456-789-000',
            'business_address' => '12 Mabini St, Cebu',
        ])
        ->assertSessionHasNoErrors();

    $school = $this->school->refresh();

    expect($school->logo_path)->toStartWith('schools/'.$school->getKey().'/logo-')
        ->and($school->registered_name)->toBe('St Jude Academy Educational Foundation, Inc.')
        ->and($school->tin)->toBe('123-456-789-000');

    Storage::disk(SchoolLogo::DISK)->assertExists($school->logo_path);
});

it('refuses an SVG outright', function (): void {
    $svg = UploadedFile::fake()->createWithContent(
        'logo.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    $this->actingAs(organisationAuthAs('super-admin'))
        ->patch(route('admin.organisation.update'), ['logo' => $svg])
        ->assertSessionHasErrors('logo');

    expect($this->school->refresh()->logo_path)->toBeNull();
});

it('refuses a file that is not really an image', function (): void {
    // A renamed executable passes an extension check and fails this one.
    $fake = UploadedFile::fake()->createWithContent('logo.png', 'not an image');

    $this->actingAs(organisationAuthAs('super-admin'))
        ->patch(route('admin.organisation.update'), ['logo' => $fake])
        ->assertSessionHasErrors('logo');
});

it('refuses a file over the size cap', function (): void {
    // It is embedded in every invoice and payslip, so the cap is about
    // document weight as much as storage.
    $big = UploadedFile::fake()->image('logo.png', 500, 500)->size(2048);

    $this->actingAs(organisationAuthAs('super-admin'))
        ->patch(route('admin.organisation.update'), ['logo' => $big])
        ->assertSessionHasErrors('logo');
});

/* ── Replacing and removing ─────────────────────────────────────────── */

it('deletes the old file when a logo is replaced', function (): void {
    $user = organisationAuthAs('super-admin');

    $this->actingAs($user)->patch(route('admin.organisation.update'), [
        'logo' => pngUpload('first.png'),
    ]);
    $first = $this->school->refresh()->logo_path;

    $this->actingAs($user)->patch(route('admin.organisation.update'), [
        'logo' => pngUpload('second.jpg'),
    ]);
    $second = $this->school->refresh()->logo_path;

    expect($second)->not->toBe($first);
    Storage::disk(SchoolLogo::DISK)->assertMissing($first);
    Storage::disk(SchoolLogo::DISK)->assertExists($second);
});

it('keeps the stored logo when the field is submitted empty', function (): void {
    $user = organisationAuthAs('super-admin');

    $this->actingAs($user)->patch(route('admin.organisation.update'), [
        'logo' => pngUpload(),
    ]);
    $stored = $this->school->refresh()->logo_path;

    // Editing the address must not wipe the logo just because the file input
    // could not resend a file it was never given.
    $this->actingAs($user)->patch(route('admin.organisation.update'), [
        'business_address' => 'New address',
    ])->assertSessionHasNoErrors();

    expect($this->school->refresh()->logo_path)->toBe($stored);
    Storage::disk(SchoolLogo::DISK)->assertExists($stored);
});

it('removes the logo when asked explicitly', function (): void {
    $user = organisationAuthAs('super-admin');

    $this->actingAs($user)->patch(route('admin.organisation.update'), [
        'logo' => pngUpload(),
    ]);
    $stored = $this->school->refresh()->logo_path;

    $this->actingAs($user)->patch(route('admin.organisation.update'), [
        'remove_logo' => true,
    ]);

    expect($this->school->refresh()->logo_path)->toBeNull();
    Storage::disk(SchoolLogo::DISK)->assertMissing($stored);
});

it('lets an upload win over a removal ticked in the same submit', function (): void {
    $user = organisationAuthAs('super-admin');

    $this->actingAs($user)->patch(route('admin.organisation.update'), [
        'logo' => pngUpload(),
        'remove_logo' => true,
    ]);

    // Otherwise choosing a replacement while remove was still ticked would
    // delete the file just uploaded.
    expect($this->school->refresh()->logo_path)->not->toBeNull();
});

/* ── Tenancy ────────────────────────────────────────────────────────── */

it('never writes into another school\'s folder', function (): void {
    $other = School::factory()->create(['slug' => 'other', 'domain' => null]);

    $this->actingAs(organisationAuthAs('super-admin'))
        ->patch(route('admin.organisation.update'), ['logo' => pngUpload()]);

    expect($this->school->refresh()->logo_path)
        ->toStartWith('schools/'.$this->school->getKey().'/')
        ->and($other->refresh()->logo_path)->toBeNull();
});

/* ── The office email ───────────────────────────────────────────────── */

it('saves the office email a parent replies to', function (): void {
    $this->actingAs(organisationAuthAs('super-admin'))
        ->patch(route('admin.organisation.update'), [
            'email' => 'office@stmarys.edu.ph',
        ])
        ->assertSessionHasNoErrors();

    expect($this->school->refresh()->email)->toBe('office@stmarys.edu.ph');
});

it('refuses an office email that is not an address', function (): void {
    $this->actingAs(organisationAuthAs('super-admin'))
        ->patch(route('admin.organisation.update'), ['email' => 'not-an-address'])
        ->assertSessionHasErrors('email');
});

it('lets a school clear its office email', function (): void {
    // Clearing it is a real choice, not an accident: a school with no shared
    // inbox is better off with no Reply-To than with one nobody reads.
    //
    // Set through the app rather than through the model, deliberately. Spatie
    // binds ONE School instance as the current tenant and a request reads that
    // one; a `$this->school->update()` here writes the row without touching
    // it, so the request that follows would fill a stale attribute, find it
    // unchanged, and save nothing. A real request re-resolves the tenant, so
    // going through the endpoint is both the honest path and the passing one.
    $user = organisationAuthAs('super-admin');

    $this->actingAs($user)->patch(route('admin.organisation.update'), [
        'email' => 'office@stmarys.edu.ph',
    ]);

    $this->actingAs($user)
        ->patch(route('admin.organisation.update'), ['email' => ''])
        ->assertSessionHasNoErrors();

    expect($this->school->refresh()->email)->toBeNull();
});

it('hands the office email back to the form', function (): void {
    $user = organisationAuthAs('super-admin');

    $this->actingAs($user)->patch(route('admin.organisation.update'), [
        'email' => 'office@stmarys.edu.ph',
    ]);

    $this->actingAs($user)
        ->get(route('admin.organisation.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('organisation.email', 'office@stmarys.edu.ph'));
});
