<?php

declare(strict_types=1);

namespace App\Mail;

use App\Actions\Accounting\SendInvoiceEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invoice reaching the person who has to pay it.
 *
 * The app's first outbound mail, and the first thing it has ever sent to
 * someone outside the school. Two decisions follow from that:
 *
 * **Queued.** An approval must not depend on a mail server. `ApproveInvoice`
 * posts to the ledger inside a transaction; a synchronous send that failed
 * there would roll the approval back, so an invoice someone approved would
 * silently un-approve because SMTP was down.
 *
 * **A link AND the document.** The link came first and still carries the
 * paying: it is tokenised, and access to it dies when the invoice is voided.
 * The PDF now travels beside it because a parent asked to be sent an invoice
 * expects an invoice, and because a school's own filing works in documents.
 *
 * Know what that trades away. The PDF carries the payer's name, address and
 * TIN, and once attached, that copy is in an inbox and on relays this app will
 * never reach — voiding the invoice withdraws the link and nothing else. It is
 * the ordinary practice for billing, and it is a one-way door per message.
 *
 * The bytes arrive already rendered, base64 on a property, rather than being
 * built inside `attachments()` from an id. Rendering here would re-read the
 * invoice at send time and could attach a document the operator never saw, and
 * a template failure would surface as a dead queue job instead of an error in
 * front of the person who pressed Send. See {@see SendInvoiceEmail}.
 *
 * **The school's name, the platform's address.** The From line shows the
 * school so a parent recognises the sender in a crowded inbox, but the address
 * behind it stays `MAIL_FROM_ADDRESS` — the mailbox this host is actually
 * authorised to send as. Putting the school's own address there instead would
 * fail SPF and DKIM at every major provider, because nothing in the school's
 * DNS names this server, and Gmail and Yahoo have binned unauthenticated bulk
 * mail since 2024. `Reply-To` is what carries the school's address, so the
 * body's "reply to this message and the school office will sort it out" is
 * true without staking deliverability on a DNS record nobody here controls.
 *
 * Scalars only, no models: the payload is serialised onto the queue, and a
 * model would be re-fetched at send time and might have changed.
 */
final class InvoiceIssuedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $schoolName,
        public readonly string $invoiceNumber,
        public readonly string $payerName,
        public readonly string $amount,
        public readonly ?string $dueDate,
        public readonly ?string $studentName,
        public readonly string $payUrl,
        /** Absolute URL of the school's logo, or null when it has none. */
        public readonly ?string $logoUrl = null,
        /** The school office's own address, for replies. Null when unset. */
        public readonly ?string $schoolEmail = null,
        /**
         * The invoice PDF, base64 encoded, or null to send the link alone.
         *
         * Encoded because the queue payload is JSON: raw PDF bytes are not
         * valid UTF-8 and would fail to serialise on the way to Redis.
         */
        public readonly ?string $pdfBase64 = null,
        public readonly ?string $pdfFilename = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // The address is the configured sender; only the display name is
            // the school's. See the class docblock for why that split exists.
            from: new Address(
                (string) config('mail.from.address'),
                $this->schoolName,
            ),
            replyTo: $this->schoolEmail === null || $this->schoolEmail === ''
                ? []
                : [new Address($this->schoolEmail, $this->schoolName)],
            subject: sprintf('%s from %s', $this->invoiceNumber, $this->schoolName),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invoice-issued');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if ($this->pdfBase64 === null || $this->pdfBase64 === '') {
            return [];
        }

        $bytes = base64_decode($this->pdfBase64, true);

        // Strict decode, and a corrupt payload sends the message without the
        // attachment rather than throwing. The link in the body still pays the
        // invoice; failing the whole send over a damaged copy of a document
        // the recipient can already reach would be the worse outcome.
        if ($bytes === false || $bytes === '') {
            return [];
        }

        return [
            Attachment::fromData(fn (): string => $bytes, $this->pdfFilename ?? 'invoice.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
