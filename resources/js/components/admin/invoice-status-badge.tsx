import { Badge } from '@/components/ui/badge';
import type { InvoiceRow } from '@/types';

/**
 * Lives here rather than beside the list page that first used it. A page
 * that imports from another page gives that page a second importer, and the
 * bundler then folds it into a shared chunk with no manifest entry of its own
 * — which is how the invoice list came to be missing from a production build.
 * `tests/Architecture/PageImportsTest.php` keeps pages from importing pages.
 */
export function InvoiceStatusBadge({
    status,
}: {
    status: InvoiceRow['status'];
}) {
    if (status === 'paid') {
        return (
            <Badge className="bg-success/15 text-success hover:bg-success/15">
                Paid
            </Badge>
        );
    }

    if (status === 'partially_paid') {
        return <Badge variant="outline">Partially paid</Badge>;
    }

    if (status === 'voided') {
        return <Badge variant="secondary">Voided</Badge>;
    }

    if (status === 'draft') {
        return <Badge variant="outline">Draft</Badge>;
    }

    // Reserved since Slice 5 and never reached until invoices could be emailed
    // by hand — until then a sent invoice fell through and read as merely
    // 'Approved', which is the one thing the operator already knew.
    if (status === 'sent') {
        return <Badge variant="outline">Sent</Badge>;
    }

    return <Badge variant="outline">Approved</Badge>;
}
