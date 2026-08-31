import { Wand2 } from 'lucide-react';
import { Button } from '@/components/ui/button';

/**
 * Fills a document form with plausible data, for development and demos.
 *
 * **Not a product feature.** The caller renders it only when the server says
 * `canDemoFill`, which answers the `dev.demo-fill` gate: super-admin, and
 * never in production. The ghost styling is doing a job — it keeps the button
 * visibly not part of the product when it sits beside a real action like Back.
 *
 * Extracted on its second call site rather than its first. The invoice and
 * payment create pages both offer it now, and a copy each would mean the
 * explanation of what gates it living in two places, or more likely one.
 *
 * The state it fills lives in the form, not here: pages hold a ref to the
 * form's handle and pass its filler as `onFill`. A handle is the smallest seam
 * that keeps the button in the page header and the state where it belongs.
 */
export function DemoFillButton({ onFill }: { onFill: () => void }) {
    return (
        <Button
            type="button"
            variant="ghost"
            className="text-muted-foreground"
            onClick={onFill}
        >
            <Wand2 className="mr-1 h-4 w-4" />
            Fill with demo data
        </Button>
    );
}
