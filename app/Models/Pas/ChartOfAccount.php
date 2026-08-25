<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use Database\Factories\Pas\ChartOfAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * One account in a school's chart of accounts — the vocabulary every journal
 * entry line is written in.
 *
 * @property int $id
 * @property int $school_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property ?string $subtype
 * @property string $normal_balance
 * @property string $cash_flow_category
 * @property bool $is_cash_equivalent
 * @property ?int $parent_id
 * @property ?string $system_code
 * @property ?string $description
 * @property bool $is_active
 * @property bool $is_locked
 */
final class ChartOfAccount extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<ChartOfAccountFactory> */
    use HasFactory;

    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_INCOME = 'income';

    public const TYPE_EXPENSE = 'expense';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_ASSET,
        self::TYPE_LIABILITY,
        self::TYPE_EQUITY,
        self::TYPE_INCOME,
        self::TYPE_EXPENSE,
    ];

    public const BALANCE_DEBIT = 'debit';

    public const BALANCE_CREDIT = 'credit';

    /** @var list<string> */
    public const NORMAL_BALANCES = [
        self::BALANCE_DEBIT,
        self::BALANCE_CREDIT,
    ];

    public const CASH_FLOW_OPERATING = 'operating';

    public const CASH_FLOW_INVESTING = 'investing';

    public const CASH_FLOW_FINANCING = 'financing';

    public const CASH_FLOW_NONE = 'none';

    /** @var list<string> */
    public const CASH_FLOW_CATEGORIES = [
        self::CASH_FLOW_OPERATING,
        self::CASH_FLOW_INVESTING,
        self::CASH_FLOW_FINANCING,
        self::CASH_FLOW_NONE,
    ];

    /**
     * Accounts the software posts to by itself. A row carrying one of these
     * is `is_locked` and cannot be deleted or re-coded through the UI —
     * removing it would break invoice, bill, payment, or payroll posting.
     */
    public const SYSTEM_AR_CONTROL = 'AR_CONTROL';

    public const SYSTEM_AP_CONTROL = 'AP_CONTROL';

    public const SYSTEM_VAT_OUTPUT = 'VAT_OUTPUT';

    public const SYSTEM_VAT_INPUT = 'VAT_INPUT';

    public const SYSTEM_RETAINED_EARNINGS = 'RETAINED_EARNINGS';

    public const SYSTEM_PAYROLL_CLEARING = 'PAYROLL_CLEARING';

    /**
     * Money received that no invoice has claimed yet — a liability until it
     * is allocated.
     *
     * Deliberately NOT `2400 Unearned Tuition Revenue`, which answers a
     * different question: tuition billed but not yet earned. An unallocated
     * receipt is cash held against nothing at all. Merging the two would
     * leave neither figure trustworthy.
     */
    public const SYSTEM_CUSTOMER_ADVANCES = 'CUSTOMER_ADVANCES';

    /** The mirror on the buying side: money paid ahead of any bill. */
    public const SYSTEM_SUPPLIER_ADVANCES = 'SUPPLIER_ADVANCES';

    /** @var list<string> */
    public const SYSTEM_CODES = [
        self::SYSTEM_AR_CONTROL,
        self::SYSTEM_AP_CONTROL,
        self::SYSTEM_VAT_OUTPUT,
        self::SYSTEM_VAT_INPUT,
        self::SYSTEM_RETAINED_EARNINGS,
        self::SYSTEM_PAYROLL_CLEARING,
        self::SYSTEM_CUSTOMER_ADVANCES,
        self::SYSTEM_SUPPLIER_ADVANCES,
    ];

    protected $table = 'pas_chart_of_accounts';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'code',
        'name',
        'type',
        'subtype',
        'normal_balance',
        'cash_flow_category',
        'is_cash_equivalent',
        'parent_id',
        'system_code',
        'description',
        'is_active',
        'is_locked',
    ];

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return ChartOfAccountFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            'parent_id' => 'integer',
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
            'is_cash_equivalent' => 'boolean',
        ];
    }

    /**
     * The side of the ledger on which an account of `$type` increases.
     *
     * Assets and expenses are debit-normal; liabilities, equity, and income
     * are credit-normal. This is the single source of truth for the mapping —
     * the FormRequests and the seeder both derive `normal_balance` through
     * here rather than restating the rule, so the two can never drift.
     *
     * @throws InvalidArgumentException When `$type` is not a known account type.
     */
    public static function normalBalanceForType(string $type): string
    {
        return match ($type) {
            self::TYPE_ASSET, self::TYPE_EXPENSE => self::BALANCE_DEBIT,
            self::TYPE_LIABILITY, self::TYPE_EQUITY, self::TYPE_INCOME => self::BALANCE_CREDIT,
            default => throw new InvalidArgumentException(
                "Unknown chart-of-accounts type '{$type}'."
            ),
        };
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_balance === self::BALANCE_DEBIT;
    }

    /**
     * Signed movement contributed by a debit/credit pair, expressed in the
     * account's own natural direction.
     *
     * This is the correction to the formula in the client's requirements
     * doc, which gives only `Ending = Opening + Debits - Credits`. That form
     * holds for debit-normal accounts; applying it to a liability, equity, or
     * income account reports every balance with the wrong sign. Report code
     * must accumulate through this method rather than subtracting inline.
     */
    public function movementCentavos(int $debitCentavos, int $creditCentavos): int
    {
        return $this->isDebitNormal()
            ? $debitCentavos - $creditCentavos
            : $creditCentavos - $debitCentavos;
    }

    /**
     * Restrict to active accounts.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Restrict to accounts that are themselves cash or a cash equivalent.
     *
     * Distinct from `cash_flow_category`, which classifies the movements of
     * every account into operating / investing / financing. This scope
     * answers the other question the Cash Flow Statement asks: which
     * balances are the cash those sections reconcile to.
     *
     * Also the allowlist for money movement — a payment may only be received
     * into, or paid out of, an account this scope returns.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCashEquivalent(Builder $query): Builder
    {
        return $query->where('is_cash_equivalent', true);
    }

    /**
     * Restrict to one account type.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<TaxRate, $this> */
    public function taxRates(): HasMany
    {
        return $this->hasMany(TaxRate::class, 'account_id');
    }
}
