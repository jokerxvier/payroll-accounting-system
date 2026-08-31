<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Actions\Payments\MintInvoicePayToken;
use App\Mail\InvoiceIssuedMail;
use App\Models\Pas\Invoice;
use App\Models\Pas\School;
use App\Services\Accounting\InvoicePdf;
use App\Services\SchoolLogo;
use DomainException;
use Illuminate\Support\Facades\Mail;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Sends an issued invoice to the person who has to pay it.
 *
 * **Never call this from inside `ApproveInvoice`.** That action wraps the
 * ledger post in a transaction, `QUEUE_CONNECTION` is `sync` in development so
 * a queued mailable runs inline, and `MintInvoicePayToken` opens a nested
 * transaction of its own. A send that failed in there would roll back the
 * approval and its journal entry — an invoice someone approved would silently
 * un-approve — and a token minted in there would die with the rollback. The
 * caller approves first, then sends once the commit has happened.
 *
 * **`sent_at` is claimed before the send, not after.** Horizon retries three
 * times; a handoff that succeeds and then times out on close would otherwise
 * email the family the same invoice two or three times. Same check-then-claim
 * shape as `GatewayWebhookController`.
 *
 * **A recipient given explicitly is a person's instruction**, and is treated
 * as one: it overrides the payer's address on file, and it overrides the
 * already-sent guard above. That guard exists so a *retried approval* cannot
 * mail the same document twice — it was never meant to stop an operator who
 * has been told the invoice went to the wrong address, or never arrived.
 * Called with no recipient, this behaves exactly as it always has, which is
 * what keeps the unattended approval path safe.
 */
final class SendInvoiceEmail
{
    public function __construct(
        private readonly MintInvoicePayToken $tokens,
        private readonly SchoolLogo $logos,
        private readonly InvoicePdf $pdf,
    ) {}

    /**
     * @param  ?string  $recipient  Where to send it. Null asks for the payer's
     *                              address on file, which is the unattended
     *                              path; anything else is a person's explicit
     *                              instruction and wins over both the stored
     *                              address and the already-sent guard.
     * @return string A sentence for the operator: what happened, or why not.
     *
     * @throws DomainException The document is not in a state that can be sent.
     */
    public function execute(Invoice $invoice, ?string $recipient = null): string
    {
        if (! $invoice->isIssued()) {
            throw new DomainException(
                'Only an issued document can be sent. Approve it first.',
            );
        }

        $wasAskedFor = $recipient !== null && $recipient !== '';

        if ($invoice->sent_at !== null && ! $wasAskedFor) {
            return sprintf('%s was already sent.', $invoice->number);
        }

        $invoice->loadMissing('contact');
        $contact = $invoice->contact;

        if ($contact === null) {
            // The mail is addressed to a person by name, so a missing payer
            // record stops the send even when an address was typed.
            return sprintf(
                '%s could not be sent: its payer record is missing.',
                $invoice->number,
            );
        }

        $email = $wasAskedFor ? $recipient : $contact->email;

        if ($email === null || $email === '') {
            // Recorded rather than thrown: a payer with no email address is an
            // ordinary gap in the records, not a failure of the approval that
            // just happened.
            return sprintf(
                '%s was approved, but %s has no email address on file, so nothing was sent.',
                $invoice->number,
                $contact->name,
            );
        }

        $tenant = Tenant::current();

        if (! $tenant instanceof School) {
            throw new DomainException('No school is current, so the pay link cannot be built.');
        }

        $token = $this->tokens->execute($invoice);

        // Rendered here, in front of whoever pressed Send, rather than inside
        // the mailable at delivery time. Two reasons, and the second is the
        // one that bites: the attachment is then exactly the document as it
        // stood when the send was ordered, and a template failure is an error
        // the operator sees rather than a queue job that dies quietly having
        // told nobody the invoice never went.
        $pdf = base64_encode($this->pdf->bytes($invoice));

        // Claimed before the mail is queued. A crash between the two loses one
        // email, which someone can resend; the other order sends two.
        $invoice->forceFill([
            'sent_at' => now(),
            'sent_to' => $email,
            // Only a document still sitting at `approved` becomes `sent`.
            // Re-sending one that is already partially paid must not walk its
            // status backwards: `sent` reads as "nothing has arrived", and the
            // receivable reports would follow the status rather than the money.
            'status' => $invoice->status === Invoice::STATUS_APPROVED
                ? Invoice::STATUS_SENT
                : $invoice->status,
        ])->save();

        Mail::to($email)->queue(new InvoiceIssuedMail(
            schoolName: $tenant->registered_name ?: $tenant->name,
            invoiceNumber: $invoice->number ?? ('Invoice #'.$invoice->getKey()),
            payerName: $contact->name,
            amount: '₱'.number_format($invoice->total_centavos / 100, 2),
            dueDate: $invoice->due_date?->format('j F Y'),
            studentName: $invoice->student_name,
            payUrl: route('public.pay.show', [
                'slug' => $tenant->slug,
                'token' => $token,
            ]),
            // A URL, not an embedded copy: the message stays small, and it
            // resolves the same whether the send runs in-request or on a
            // worker that shares no filesystem with the web node. Needs
            // `storage:link` and a reachable APP_URL, both of which the
            // sidebar logo already depends on.
            logoUrl: $this->logos->absoluteUrl($tenant),
            schoolEmail: $tenant->email,
            pdfBase64: $pdf,
            pdfFilename: $this->pdf->filename($invoice),
        ));

        return sprintf('%s was sent to %s.', $invoice->number, $email);
    }
}
