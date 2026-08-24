<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\Pas\DocumentNumberSeriesFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A controlled numbering sequence for one document type.
 *
 * @property int $id
 * @property int $school_id
 * @property string $document_type
 * @property string $label
 * @property string $prefix
 * @property int $next_number
 * @property int $padding
 * @property ?string $atp_number
 * @property ?CarbonImmutable $permit_issued_at
 * @property ?int $serial_start
 * @property ?int $serial_end
 * @property bool $is_active
 */
final class DocumentNumberSeries extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<DocumentNumberSeriesFactory> */
    use HasFactory;

    /**
     * Document types a series can govern.
     *
     * Which of these a given school actually issues depends on the permits it
     * holds — Open Question 1. Declaring the constants costs nothing and lets
     * the rest of the code refer to them; a school simply has no series row
     * for a document it does not issue.
     */
    public const TYPE_SALES_INVOICE = 'sales_invoice';

    public const TYPE_OFFICIAL_RECEIPT = 'official_receipt';

    public const TYPE_CREDIT_NOTE = 'credit_note';

    public const TYPE_BILL = 'bill';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_SALES_INVOICE,
        self::TYPE_OFFICIAL_RECEIPT,
        self::TYPE_CREDIT_NOTE,
        self::TYPE_BILL,
    ];

    protected $table = 'pas_document_number_series';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'document_type',
        'label',
        'prefix',
        'next_number',
        'padding',
        'atp_number',
        'permit_issued_at',
        'serial_start',
        'serial_end',
        'is_active',
    ];

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return DocumentNumberSeriesFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            'next_number' => 'integer',
            'padding' => 'integer',
            'permit_issued_at' => 'immutable_date',
            'serial_start' => 'integer',
            'serial_end' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Render a sequence number as the document number an operator sees.
     */
    public function format(int $number): string
    {
        return $this->prefix.str_pad((string) $number, $this->padding, '0', STR_PAD_LEFT);
    }

    /**
     * Whether `$number` falls inside the ATP-issued range.
     *
     * True when no range is configured — an unregistered series is
     * unconstrained, which is the state every series starts in until the
     * client supplies their permit details.
     */
    public function isWithinAuthorisedRange(int $number): bool
    {
        if ($this->serial_start !== null && $number < $this->serial_start) {
            return false;
        }

        if ($this->serial_end !== null && $number > $this->serial_end) {
            return false;
        }

        return true;
    }

    /** How many numbers remain in the authorised range, or null if unbounded. */
    public function remainingInRange(): ?int
    {
        if ($this->serial_end === null) {
            return null;
        }

        return max(0, $this->serial_end - $this->next_number + 1);
    }

    public function hasAuthorityToPrint(): bool
    {
        return $this->atp_number !== null;
    }
}
