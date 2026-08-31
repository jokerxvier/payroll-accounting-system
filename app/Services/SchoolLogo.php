<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Pas\School;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * One place everything asks about a school's logo.
 *
 * There are three answers, because no consumer can use another's:
 *
 *   - **`url()`** for HTML. A plain, cacheable path on the `public` disk,
 *     served by the web server rather than PHP.
 *
 *   - **`absoluteUrl()`** for email. An inbox has no origin to resolve a
 *     root-relative path against, so `/storage/...` there is a broken image
 *     every time — and the difference from `url()` is invisible until a parent
 *     opens the message.
 *
 *   - **`dataUri()`** for PDFs, and it is not an optimisation — it is the only
 *     thing that works. There is no `config/dompdf.php`, so the vendor default
 *     `enable_remote = false` applies and dompdf **refuses any http(s) image
 *     silently**, rendering nothing at all. An absolute filesystem path would
 *     work for the invoice, which renders in-request, but the payslip renders
 *     in a queued job where the worker may not share a filesystem with the web
 *     node. A base64 payload is the one form that survives both.
 *
 * Every read returns null rather than throwing when the file has gone missing.
 * A logo deleted out from under the app must not take down payroll's PDF
 * generation — both templates already guard absent seller facts with `@if`.
 */
final class SchoolLogo
{
    public const DISK = 'public';

    /** Keeps a 1MB cap meaningful against a base64 payload in every PDF. */
    public const MAX_KILOBYTES = 1024;

    /**
     * A URL for the browser, or null.
     *
     * Requires `php artisan storage:link` — without it this resolves to a path
     * that 404s. The PDFs are unaffected either way, because they read bytes
     * off the disk rather than fetching a URL.
     */
    public function url(?School $school): ?string
    {
        $path = $school?->logo_path;

        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk(self::DISK)->url($path);
    }

    /**
     * An absolute URL for a mail client, or null.
     *
     * The disk's configured `url` is already absolute in every environment
     * this app ships with, because `config/filesystems.php` builds it from
     * `APP_URL`. This does not trust that: a disk configured without one falls
     * back to a root-relative `/storage/...`, which is correct on a page and
     * broken in an inbox, and nothing fails loudly when it happens.
     */
    public function absoluteUrl(?School $school): ?string
    {
        $url = $this->url($school);

        if ($url === null) {
            return null;
        }

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $url
            : URL::to($url);
    }

    /**
     * A `data:` URI for dompdf, or null.
     *
     * @see self for why this exists rather than a URL or a path.
     */
    public function dataUri(?School $school): ?string
    {
        $path = $school?->logo_path;

        if ($path === null || $path === '') {
            return null;
        }

        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($path)) {
            return null;
        }

        $bytes = $disk->get($path);

        if ($bytes === null || $bytes === '') {
            return null;
        }

        return sprintf(
            'data:%s;base64,%s',
            $disk->mimeType($path) ?: 'image/png',
            base64_encode($bytes),
        );
    }

    /**
     * Store a new logo and return its path, deleting whatever it replaces.
     *
     * The filename carries a content hash so a replacement always changes the
     * URL — otherwise a browser that cached the old one keeps showing it, and
     * the school reports the upload as broken.
     *
     * The extension comes from the file's own guessed extension, never from
     * the client-supplied name: a `.png` that is really something else has
     * already been refused by validation, and trusting the name would be the
     * one place that decision could be undone.
     */
    public function store(School $school, UploadedFile $file): string
    {
        $extension = $file->guessExtension() ?: 'png';
        $hash = substr((string) hash_file('sha256', $file->getRealPath()), 0, 12);

        $path = sprintf('schools/%d/logo-%s.%s', $school->getKey(), $hash, $extension);

        Storage::disk(self::DISK)->put($path, $file->getContent());

        $this->deleteFile($school->logo_path);

        return $path;
    }

    /**
     * Remove a school's logo entirely.
     */
    public function clear(School $school): void
    {
        $this->deleteFile($school->logo_path);
    }

    /**
     * Best-effort. A file already gone is the outcome we wanted.
     */
    private function deleteFile(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }
}
