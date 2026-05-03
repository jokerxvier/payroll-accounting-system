import type { SubscriptionSchedule } from '@/types';

/**
 * Human-readable labels for `SubscriptionSchedule`. Centralized here so the
 * four Week 7 cards (deductions, allowances, loans, adjustments) render
 * identical wording without duplicating the lookup table.
 */
export const SCHEDULE_LABEL: Record<SubscriptionSchedule, string> = {
    every_run: 'Every run',
    first_half: 'First half',
    second_half: 'Second half',
    monthly_first: 'Monthly · first run',
    monthly_last: 'Monthly · last run',
};
