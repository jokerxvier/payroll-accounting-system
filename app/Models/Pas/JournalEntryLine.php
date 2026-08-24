<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use App\ValueObjects\Money;
use Database\Factories\Pas\JournalEntryLineFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single debit or credit against one account.
 *
 * Exactly one of `debit_centavos` / `credit_centavos` is non-zero. Two
 * unsigned columns rather than one signed amount because that is how a
 * ledger is read and printed (`THEME.md` §6.3), and it leaves no question
 * about which sign means what.
 *
 * @property int $id
 * @property int $school_id
 * @property int $journal_entry_id
 * @property int $line_number
 * @property int $account_id
 * @property int $debit_centavos
 * @property int $credit_centavos
 * @property ?string $description
 */
final class JournalEntryLine extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<JournalEntryLineFactory> */
    use HasFactory;

    protected $table = 'pas_journal_entry_lines';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'journal_entry_id',
        'line_number',
        'account_id',
        'debit_centavos',
        'credit_centavos',
        'description',
    ];

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return JournalEntryLineFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            'journal_entry_id' => 'integer',
            'line_number' => 'integer',
            'account_id' => 'integer',
            'debit_centavos' => 'integer',
            'credit_centavos' => 'integer',
        ];
    }

    public function isDebit(): bool
    {
        return $this->debit_centavos !== 0;
    }

    public function isCredit(): bool
    {
        return $this->credit_centavos !== 0;
    }

    public function debit(): Money
    {
        return Money::fromCentavos($this->debit_centavos);
    }

    public function credit(): Money
    {
        return Money::fromCentavos($this->credit_centavos);
    }

    /**
     * The line's effect on the account's balance, in the account's own
     * natural direction.
     *
     * Delegates to {@see ChartOfAccount::movementCentavos()} so the
     * debit-normal / credit-normal rule lives in exactly one place. Report
     * code must accumulate through here rather than subtracting inline —
     * the client's requirements doc gives only the debit-normal form, which
     * sign-flips every liability, equity, and income balance.
     */
    public function signedMovementCentavos(?ChartOfAccount $account = null): int
    {
        $account ??= $this->account;

        return $account->movementCentavos($this->debit_centavos, $this->credit_centavos);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<ChartOfAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
