<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use App\ValueObjects\Money;
use Database\Factories\Pas\TaxRateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tax rate applied to invoice and bill lines.
 *
 * The rate is stored in integer basis points (`rate_bps`): 12% is `1200`,
 * 0% is `0`. See {@see self::taxOn()} for why.
 *
 * @property int $id
 * @property int $school_id
 * @property string $code
 * @property string $name
 * @property int $rate_bps
 * @property string $type
 * @property ?int $account_id
 * @property bool $is_active
 */
final class TaxRate extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<TaxRateFactory> */
    use HasFactory;

    /** Output VAT — collected on sales, a liability owed to the BIR. */
    public const TYPE_VAT_SALES = 'vat_sales';

    /** Input VAT — paid on purchases, creditable against output VAT. */
    public const TYPE_VAT_PURCHASE = 'vat_purchase';

    /** VAT-exempt. Zero tax, but reported as its own subtotal. */
    public const TYPE_EXEMPT = 'exempt';

    /** Zero-rated. Zero tax, reported separately from exempt. */
    public const TYPE_ZERO_RATED = 'zero_rated';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_VAT_SALES,
        self::TYPE_VAT_PURCHASE,
        self::TYPE_EXEMPT,
        self::TYPE_ZERO_RATED,
    ];

    /** Basis points in 100% — the divisor for every rate calculation. */
    public const BPS_DENOMINATOR = 10_000;

    protected $table = 'pas_tax_rates';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'code',
        'name',
        'rate_bps',
        'type',
        'account_id',
        'is_active',
    ];

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return TaxRateFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            'rate_bps' => 'integer',
            'account_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Tax payable on a VAT-**exclusive** amount.
     *
     * Integer throughout: multiply by the basis points, then divide by 10,000
     * once, letting Money apply banker's rounding at the single division.
     * Multiplying by a float rate would reintroduce exactly the drift the
     * Money value object exists to prevent.
     */
    public function taxOn(Money $netAmount): Money
    {
        if ($this->rate_bps === 0) {
            return Money::zero();
        }

        return $netAmount->times($this->rate_bps)->dividedBy(self::BPS_DENOMINATOR);
    }

    /**
     * Tax already embedded in a VAT-**inclusive** amount.
     *
     * For a gross G at rate r basis points, the tax component is
     * `G * r / (10,000 + r)` — not `G * r / 10,000`, which would over-state
     * the tax by taxing the tax. Both branches round once, at the end.
     */
    public function taxWithin(Money $grossAmount): Money
    {
        if ($this->rate_bps === 0) {
            return Money::zero();
        }

        return $grossAmount
            ->times($this->rate_bps)
            ->dividedBy(self::BPS_DENOMINATOR + $this->rate_bps);
    }

    /**
     * True when this rate produces a tax line at all. Exempt and zero-rated
     * sales post no tax but still need their own invoice subtotals, so they
     * are distinct types rather than a shared 0% rate.
     */
    public function postsTax(): bool
    {
        return $this->rate_bps > 0
            && in_array($this->type, [self::TYPE_VAT_SALES, self::TYPE_VAT_PURCHASE], true);
    }

    /**
     * Human-facing percentage, e.g. `1200` → `"12%"`, `1250` → `"12.5%"`.
     */
    public function ratePercentLabel(): string
    {
        $percent = $this->rate_bps / 100;

        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'%';
    }

    /**
     * Restrict to active rates.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return BelongsTo<ChartOfAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
