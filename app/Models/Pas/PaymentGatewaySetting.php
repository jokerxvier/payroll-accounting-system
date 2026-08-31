<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use App\Services\Payments\GatewayAccountResolver;
use Database\Factories\Pas\PaymentGatewaySettingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One school's credentials for one gateway, in one mode.
 *
 * Per-school because each school is its own registered entity with its own
 * merchant account — the money settles into that school's bank, and the
 * school is the merchant of record. The storage pattern is lifted wholesale
 * from `School::lms_db_password`: `encrypted` cast for anything secret,
 * `auditExclude()` so it never reaches the audit trail, decrypted only at the
 * moment of use.
 *
 * `test` and `live` are separate rows rather than one row with a flag. The
 * failure this prevents is small and expensive: a mode toggle sitting beside
 * a single credential field is how someone charges a real card while
 * believing they are in a sandbox.
 *
 * @property int $id
 * @property int $school_id
 * @property string $provider
 * @property string $mode
 * @property ?string $publishable_key
 * @property ?string $secret_key
 * @property ?string $webhook_secret
 * @property ?int $cash_account_id
 * @property ?int $fee_account_id
 * @property bool $is_active
 */
final class PaymentGatewaySetting extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<PaymentGatewaySettingFactory> */
    use HasFactory;

    public const PROVIDER_PAYMONGO = 'paymongo';

    public const PROVIDER_STRIPE = 'stripe';

    /** @var list<string> */
    public const PROVIDERS = [
        self::PROVIDER_PAYMONGO,
        self::PROVIDER_STRIPE,
    ];

    public const MODE_TEST = 'test';

    public const MODE_LIVE = 'live';

    /** @var list<string> */
    public const MODES = [
        self::MODE_TEST,
        self::MODE_LIVE,
    ];

    protected $table = 'pas_payment_gateway_settings';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'provider',
        'mode',
        'publishable_key',
        'secret_key',
        'webhook_secret',
        'cash_account_id',
        'fee_account_id',
        'is_active',
    ];

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return PaymentGatewaySettingFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            // Same cast, same APP_KEY caveat, as School::lms_db_password:
            // rotating APP_KEY without re-encrypting bricks these.
            'secret_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'cash_account_id' => 'integer',
            'fee_account_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Keep the gateway credentials out of the audit trail.
     *
     * Identical reasoning to {@see School::auditExclude()} and with sharper
     * consequences: a leaked secret key can move money. The columns are
     * encrypted at rest, but an audit row is a second copy in a table
     * auditors can export. That a key changed stays visible — `updated_at`
     * moves and the surrounding columns are recorded — but never its value.
     *
     * @return list<string>
     */
    public function auditExclude(): array
    {
        return ['secret_key', 'webhook_secret'];
    }

    /**
     * Ready to take money: active, and carrying everything posting needs.
     *
     * A row can legitimately be half-filled — someone pastes the keys before
     * the gateway dashboard has issued a webhook secret. Half-filled is
     * savable and simply not usable, which is why this is a method rather
     * than a column.
     */
    public function isUsable(): bool
    {
        return $this->is_active
            && $this->secret_key !== null && $this->secret_key !== ''
            && $this->webhook_secret !== null && $this->webhook_secret !== ''
            // Whether the accounts RESOLVE, not whether the columns are set.
            // Both are overrides now — null means "use the school's default" —
            // so asking about the column would call every correctly
            // configured gateway unusable. Asking the resolver also keeps a
            // missing system account failing HERE, where an operator sees it,
            // rather than in the webhook after a customer has paid.
            && app(GatewayAccountResolver::class)->bothResolve($this);
    }

    /**
     * What the admin screen is allowed to see of a secret.
     *
     * Inertia props ship to the browser, so the value itself must never be
     * sent. Four trailing characters are enough to tell two keys apart when
     * checking which one is installed, and not enough to be worth stealing.
     */
    public function maskedSecret(): ?string
    {
        if ($this->secret_key === null || $this->secret_key === '') {
            return null;
        }

        return '••••'.mb_substr($this->secret_key, -4);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /** @return BelongsTo<ChartOfAccount, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'cash_account_id');
    }

    /** @return BelongsTo<ChartOfAccount, $this> */
    public function feeAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'fee_account_id');
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
