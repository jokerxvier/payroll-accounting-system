<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Throwable;

/**
 * The pay link, as something a phone can read off paper.
 *
 * A printed invoice is the case that makes this necessary. The public pay URL
 * carries a 40-character random token, which nobody is going to retype from a
 * sheet of paper — so on paper the QR *is* the link, and the URL underneath it
 * is only there for whoever is reading the PDF on a screen.
 *
 * **No new dependency.** `bacon/bacon-qr-code` is already installed as a
 * transitive requirement of `laravel/fortify`, which uses it to draw the 2FA
 * enrolment code.
 *
 * PNG rather than SVG, and a `data:` URI rather than a path, because the
 * consumer is dompdf: it runs with `enable_remote` off and would silently
 * render nothing for a URL, and its SVG support is not good enough to trust
 * with a document a parent has to scan.
 */
final class InvoiceQrCode
{
    /**
     * Roughly 38mm on A4 at 96dpi — large enough for a phone camera to lock
     * on without the code dominating the page it is printed on.
     */
    private const SIZE_PX = 360;

    /**
     * A quiet zone of 1 module rather than the spec's 4. The block already
     * sits inside its own padded box, so the surrounding whitespace is
     * supplied by the layout instead of by the image.
     */
    private const MARGIN_MODULES = 1;

    /**
     * @return string|null Null when the code could not be drawn, which the
     *                     caller renders as the bare URL rather than as an
     *                     error.
     */
    public function dataUri(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        // The backend needs ext-imagick, which is not guaranteed on every
        // host this deploys to. Checked rather than assumed: an invoice that
        // will not render is a worse outcome than one without a QR code, and
        // the same rule already governs the school logo — a missing image
        // must never take PDF generation down.
        if (! extension_loaded('imagick')) {
            return null;
        }

        try {
            $writer = new Writer(new ImageRenderer(
                new RendererStyle(self::SIZE_PX, self::MARGIN_MODULES),
                new ImagickImageBackEnd('png'),
            ));

            $png = $writer->writeString($url);
        } catch (Throwable) {
            // Deliberately swallowed. Every caller is mid-render of a
            // document somebody is waiting for, and none of them has a better
            // answer than leaving the code out.
            return null;
        }

        if ($png === '') {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
