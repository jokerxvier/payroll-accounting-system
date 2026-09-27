import { Badge } from '@/components/ui/badge';
import type { PaymentRow } from '@/types';

/**
 * Lives here rather than beside the list page that first used it. A page
 * that imports from another page gives that page a second importer, and the
 * bundler then folds it into a shared chunk with no manifest entry of its own
 * — which is how the invoice list came to be missing from a production build.
 * `tests/Architecture/PageImportsTest.php` keeps pages from importing pages.
 */
export function PaymentStatusBadge({
    status,
}: {
    status: PaymentRow['status'];
}) {
    if (status === 'posted') {
        return (
            <Badge className="bg-success/15 text-success hover:bg-success/15">
                Posted
            </Badge>
        );
    }

    if (status === 'voided') {
        return <Badge variant="secondary">Voided</Badge>;
    }

    return <Badge variant="outline">Draft</Badge>;
}
