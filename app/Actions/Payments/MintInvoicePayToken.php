<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Models\Pas\Invoice;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives an invoice the unguessable identifier its public link hangs off.
 *
 * Minted on demand rather than at creation, so the number of live public URLs
 * equals the number someone deliberately created — an invoice nobody shares
 * never becomes reachable from outside at all.
 *
 * **Idempotent, and that is the point.** Re-issuing a token would silently
 * break a link already sitting in a parent's messages. Once minted it is the
 * invoice's public identity for good; the way to revoke access is to void the
 * document, not to churn the token.
 */
final class MintInvoicePayToken
{
    /**
     * @throws DomainException When the document cannot be paid online.
     */
    public function execute(Invoice $invoice): string
    {
        if ($invoice->pay_token !== null && $invoice->pay_token !== '') {
            return $invoice->pay_token;
        }

        if (! $invoice->isIssued()) {
            throw new DomainException(
                'Only an issued document can be paid online. Approve it first.'
            );
        }

        if (! $invoice->isSales()) {
            throw new DomainException(
                'A purchase bill is the supplier\'s document. There is nobody to send a pay link to.'
            );
        }

        return DB::transaction(function () use ($invoice): string {
            $token = Str::random(40);

            $invoice->forceFill(['pay_token' => $token])->save();

            return $token;
        });
    }
}
