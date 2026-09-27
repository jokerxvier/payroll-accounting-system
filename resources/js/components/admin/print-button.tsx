import { Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';

/**
 * Prints the report on screen.
 *
 * Beside Export rather than inside it: a PDF is a file to send, and this is
 * the page in front of the reader going to their printer. The print styles
 * in app.css take the sidebar, filters and these buttons off the sheet.
 */
export function PrintButton() {
    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => window.print()}
        >
            <Printer className="mr-1 h-4 w-4" />
            Print
        </Button>
    );
}
