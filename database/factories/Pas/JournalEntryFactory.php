<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalEntry>
 */
class JournalEntryFactory extends Factory
{
    protected $model = JournalEntry::class;

    /**
     * Default: an unposted draft with no lines and no number.
     *
     * Numbers are allocated by PostJournalEntry, so a draft legitimately has
     * none. The unique is on (school_id, entry_number), and a factory that
     * invented numbers would collide as soon as two drafts existed.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entry_number' => null,
            'accounting_period_id' => null,
            'date' => CarbonImmutable::now()->startOfDay(),
            'reference' => null,
            'narration' => fake()->sentence(),
            'status' => JournalEntry::STATUS_DRAFT,
            'source_type' => null,
            'source_id' => null,
            'total_debit_centavos' => 0,
            'total_credit_centavos' => 0,
            'posted_at' => null,
            'posted_by_user_id' => null,
            'reversed_at' => null,
            'reversed_by_user_id' => null,
            'reversal_of_entry_id' => null,
        ];
    }

    public function on(CarbonImmutable $date): self
    {
        return $this->state(fn (): array => ['date' => $date]);
    }

    public function pending(): self
    {
        return $this->state(fn (): array => ['status' => JournalEntry::STATUS_PENDING]);
    }

    /**
     * A already-posted entry.
     *
     * Sets the stamps directly rather than going through PostJournalEntry —
     * for tests that need a posted row as a fixture rather than as the thing
     * under test. Tests of the posting rules themselves must call the action.
     */
    public function posted(int $totalCentavos = 100_000): self
    {
        return $this->state(fn (): array => [
            'status' => JournalEntry::STATUS_POSTED,
            'entry_number' => 'JE-'.CarbonImmutable::now()->format('Y').'-'.fake()->unique()->numerify('#####'),
            'total_debit_centavos' => $totalCentavos,
            'total_credit_centavos' => $totalCentavos,
            'posted_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * A posted entry that has already been reversed.
     *
     * Note it stays POSTED — reversing does not change the original's
     * status, it only stamps it. Use this for fixtures that need an entry
     * which can no longer be reversed again.
     */
    public function reversed(): self
    {
        return $this->posted()->state(fn (): array => [
            'reversed_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * An entry abandoned before it ever posted. `voided` is only ever
     * applied to something that never reached the ledger.
     */
    public function voided(): self
    {
        return $this->state(fn (): array => [
            'status' => JournalEntry::STATUS_VOIDED,
        ]);
    }
}
