import { useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { toast } from 'sonner';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { bulkSetupProfiles } from '@/routes/employees';

/**
 * Bulk "Set up missing profiles" confirmation dialog.
 *
 * POSTs to /employees/bulk-setup-profiles which authorises against
 * EmployeeProfilePolicy::create (super-admin, payroll-officer, hr +
 * platform-admin via Gate::before). The action wraps the create loop
 * in a transaction so a mid-loop failure rolls back, and is idempotent
 * — re-clicking after every staff has a profile reports zero created.
 */
interface BulkSetupMissingDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    count: number;
}

export function BulkSetupMissingDialog({
    open,
    onOpenChange,
    count,
}: BulkSetupMissingDialogProps) {
    const form = useForm({});

    const handleConfirm = () => {
        form.post(bulkSetupProfiles().url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(
                    count === 1
                        ? 'Set up 1 payroll profile.'
                        : `Set up ${count} payroll profiles.`,
                );
                onOpenChange(false);
            },
        });
    };

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Set up {count} missing profile
                        {count === 1 ? '' : 's'}?
                    </AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        <div className="space-y-2">
                            <p>
                                Creates a payroll profile for every staff who
                                doesn&apos;t have one yet.
                            </p>
                            <p>
                                <strong>Defaults:</strong> ₱0.00 salary,
                                Regular, Monthly, Active.
                            </p>
                            <p>
                                Edit each profile via Quick edit afterwards to
                                set the correct salary.
                            </p>
                        </div>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={form.processing}>
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={(event) => {
                            event.preventDefault();
                            handleConfirm();
                        }}
                        disabled={form.processing}
                    >
                        {form.processing ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Creating…
                            </>
                        ) : (
                            <>
                                Create {count} profile
                                {count === 1 ? '' : 's'}
                            </>
                        )}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
