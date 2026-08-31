<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use Database\Factories\Pas\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone the school transacts with — a parent billed for tuition, a supplier
 * who bills the school, or both.
 *
 * One record with two flags rather than separate customer and supplier
 * tables: the shape is identical, and a counterparty that is both is common
 * enough that splitting them would mean maintaining the same entity twice.
 *
 * A contact owns its own identity. `lms_student_id` is a bare pointer with no
 * foreign key and no lookup behind it — `rules/PLAN.md` §2 does not permit
 * reading LMS student tables, so nothing populates it yet. It exists so that
 * decision stays additive.
 *
 * @property int $id
 * @property int $school_id
 * @property string $code
 * @property string $name
 * @property bool $is_customer
 * @property bool $is_supplier
 * @property ?string $tin
 * @property ?string $email
 * @property ?string $phone
 * @property ?string $address
 * @property ?int $receivable_account_id
 * @property ?int $payable_account_id
 * @property ?int $lms_student_id
 * @property bool $is_active
 * @property ?string $notes
 */
final class Contact extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected $table = 'pas_contacts';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'code',
        'name',
        'is_customer',
        'is_supplier',
        'tin',
        'email',
        'phone',
        'address',
        'receivable_account_id',
        'payable_account_id',
        'lms_student_id',
        'lms_parent_id',
        'is_active',
        'notes',
    ];

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return ContactFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            'is_customer' => 'boolean',
            'is_supplier' => 'boolean',
            'receivable_account_id' => 'integer',
            'payable_account_id' => 'integer',
            'lms_student_id' => 'integer',
            'lms_parent_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * A contact must be a customer, a supplier, or both — one that is neither
     * cannot appear on any document, so it is not a contact, it is a typo.
     * Enforced in ContactRequest; exposed here so the rule has one home.
     */
    public function hasAtLeastOneRole(): bool
    {
        return $this->is_customer || $this->is_supplier;
    }

    /**
     * Restrict to contacts still in use.
     *
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
    public function scopeCustomers(Builder $query): Builder
    {
        return $query->where('is_customer', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSuppliers(Builder $query): Builder
    {
        return $query->where('is_supplier', true);
    }

    /**
     * Free-text match across the fields an operator actually searches by.
     *
     * TIN is included because it is the one unambiguous identifier — looking
     * a contact up by tax number is how you resolve two similarly-named
     * entities.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMatching(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $inner) use ($like): void {
            $inner->where('name', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->orWhere('tin', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }

    /**
     * Receivable control account override, or null to use the school's
     * AR_CONTROL system account.
     *
     * @return BelongsTo<ChartOfAccount, $this>
     */
    /**
     * The students this contact pays for.
     *
     * @return HasMany<ContactStudent, $this>
     */
    public function students(): HasMany
    {
        return $this->hasMany(ContactStudent::class);
    }

    /** @return BelongsTo<ChartOfAccount, $this> */
    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'receivable_account_id');
    }

    /** @return BelongsTo<ChartOfAccount, $this> */
    /** @return BelongsTo<ChartOfAccount, $this> */
    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'payable_account_id');
    }
}
