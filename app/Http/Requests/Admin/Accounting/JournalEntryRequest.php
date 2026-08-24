<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Accounting;

use App\Models\Pas\JournalEntry;
use App\ValueObjects\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Validates a journal entry and its lines, for both create and edit.
 *
 * One class for both: the rules are identical, and the entry number is not
 * user-supplied (PostJournalEntry allocates it), so there is no unique rule
 * needing an `ignore()`.
 *
 * The balance check lives here as well as in `PostJournalEntry`. That is
 * deliberate duplication, and `rules/CODING_STANDARDS_LARAVEL.md` §413 asks
 * for it: the FormRequest turns an imbalance into a field-level error the
 * operator can see next to the figure they mistyped, while the action is the
 * backstop that no caller can bypass — including Slice 3's payroll posting
 * and the document posting in Slices 5-7, none of which come through a form
 * at all.
 *
 * Amounts arrive as integer centavos. The client converts from pesos before
 * submitting, the same contract the allowance and payroll forms already use.
 */
final class JournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = Tenant::current()?->getKey();

        return [
            'date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:64'],
            'narration' => ['nullable', 'string', 'max:2000'],

            // Double-entry needs at least two lines. The cap is a sanity
            // bound against a runaway client, not an accounting rule.
            'lines' => ['required', 'array', 'min:2', 'max:200'],

            'lines.*.account_id' => [
                'required',
                'integer',
                // Scoped: an entry must never post into another tenant's
                // chart of accounts.
                Rule::exists('pas_chart_of_accounts', 'id')->where(
                    fn ($query) => $tenantId === null ? $query : $query->where('school_id', $tenantId)
                ),
            ],
            'lines.*.debit_centavos' => ['required', 'integer', 'min:0'],
            'lines.*.credit_centavos' => ['required', 'integer', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Only meaningful once the lines are structurally valid;
            // otherwise we would stack a confusing balance error on top of a
            // plain type error.
            if ($v->errors()->hasAny(['lines']) || $this->hasLineLevelErrors($v)) {
                return;
            }

            /** @var array<int, array<string, mixed>> $lines */
            $lines = (array) $this->input('lines', []);

            $debits = Money::zero();
            $credits = Money::zero();

            foreach ($lines as $index => $line) {
                $debit = (int) ($line['debit_centavos'] ?? 0);
                $credit = (int) ($line['credit_centavos'] ?? 0);

                // A line moves exactly one side. Setting both still lets the
                // entry "balance" while describing nothing.
                if ($debit !== 0 && $credit !== 0) {
                    $v->errors()->add(
                        "lines.{$index}.debit_centavos",
                        'A line can carry a debit or a credit, not both.'
                    );
                }

                if ($debit === 0 && $credit === 0) {
                    $v->errors()->add(
                        "lines.{$index}.debit_centavos",
                        'Enter a debit or a credit for this line.'
                    );
                }

                $debits = $debits->plus(Money::fromCentavos($debit));
                $credits = $credits->plus(Money::fromCentavos($credit));
            }

            if ($v->errors()->isNotEmpty()) {
                return;
            }

            if ($debits->isZero()) {
                $v->errors()->add('lines', 'A journal entry must move a non-zero amount.');

                return;
            }

            if (! $debits->equals($credits)) {
                $difference = $debits->minus($credits);

                $v->errors()->add('lines', sprintf(
                    'This entry does not balance: debits %s, credits %s, off by %s.',
                    $debits->toDecimalString(),
                    $credits->toDecimalString(),
                    $difference->toDecimalString(),
                ));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.min' => 'A journal entry needs at least two lines — one debit and one credit.',
            'lines.*.account_id.exists' => 'The selected account does not exist in this school.',
            'lines.*.debit_centavos.min' => 'Amounts cannot be negative. Move the figure to the other side instead.',
            'lines.*.credit_centavos.min' => 'Amounts cannot be negative. Move the figure to the other side instead.',
        ];
    }

    /**
     * Whether any individual line already failed its own rules, in which
     * case totalling them would be meaningless.
     */
    private function hasLineLevelErrors(Validator $validator): bool
    {
        foreach (array_keys($validator->errors()->toArray()) as $key) {
            if (str_starts_with((string) $key, 'lines.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entry being edited, when this is an update. Null on create.
     */
    public function routeEntry(): ?JournalEntry
    {
        $entry = $this->route('journalEntry');

        return $entry instanceof JournalEntry ? $entry : null;
    }
}
