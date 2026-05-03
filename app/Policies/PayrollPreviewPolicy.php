<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Providers\AppServiceProvider;

/**
 * Class-level policy for the real-time payroll preview screen.
 *
 * The preview is a what-if calculator — it never persists, never mutates a
 * loan balance, never decrements an outstanding adjustment. Access is widened
 * to the three roles that need to triage compensation questions:
 *
 *   - super-admin     — system-wide visibility, no carve-outs.
 *   - payroll-officer — runs payroll, must validate hypotheticals before posting.
 *   - hr              — answers compensation questions, models offers and grade changes.
 *
 * `auditor` is intentionally excluded per Stage A Decision 1: auditors review
 * the immutable record, not speculative figures. Granting preview access would
 * give them a tool the audit narrative cannot reference.
 *
 * Wired as a class-level Gate (not a model-bound policy) in
 * {@see AppServiceProvider::boot()} via
 * `Gate::define('payroll.preview', [PayrollPreviewPolicy::class, 'preview'])`.
 * There is no underlying model — this is a screen-level capability.
 */
final class PayrollPreviewPolicy
{
    public function preview(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'payroll-officer', 'hr']);
    }
}
