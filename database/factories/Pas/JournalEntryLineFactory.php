<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalEntryLine>
 */
class JournalEntryLineFactory extends Factory
{
    protected $model = JournalEntryLine::class;

    /**
     * Default: a ₱1,000.00 debit. Use debit()/credit() to be explicit —
     * a line moves exactly one side, so a factory that set both would
     * produce a row the posting action rejects.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'journal_entry_id' => JournalEntry::factory(),
            'line_number' => 1,
            'account_id' => ChartOfAccount::factory(),
            'debit_centavos' => 100_000,
            'credit_centavos' => 0,
            'description' => null,
        ];
    }

    public function debit(int $centavos): self
    {
        return $this->state(fn (): array => [
            'debit_centavos' => $centavos,
            'credit_centavos' => 0,
        ]);
    }

    public function credit(int $centavos): self
    {
        return $this->state(fn (): array => [
            'debit_centavos' => 0,
            'credit_centavos' => $centavos,
        ]);
    }

    public function forAccount(ChartOfAccount $account): self
    {
        return $this->state(fn (): array => ['account_id' => $account->getKey()]);
    }

    public function line(int $number): self
    {
        return $this->state(fn (): array => ['line_number' => $number]);
    }
}
