<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\DocumentNumberSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentNumberSeries>
 */
class DocumentNumberSeriesFactory extends Factory
{
    protected $model = DocumentNumberSeries::class;

    /**
     * Default: an unregistered sales-invoice series starting at 1.
     *
     * Unregistered because that is the honest starting state — a school has
     * no ATP on file until someone enters one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_type' => DocumentNumberSeries::TYPE_SALES_INVOICE,
            'label' => 'Sales Invoice',
            'prefix' => 'SI-',
            'next_number' => 1,
            'padding' => 6,
            'atp_number' => null,
            'permit_issued_at' => null,
            'serial_start' => null,
            'serial_end' => null,
            'is_active' => true,
        ];
    }

    public function ofType(string $type, string $prefix): self
    {
        return $this->state(fn (): array => [
            'document_type' => $type,
            'prefix' => $prefix,
            'label' => ucwords(str_replace('_', ' ', $type)),
        ]);
    }

    /** A series covered by a BIR Authority To Print with a bounded range. */
    public function withAuthority(int $start = 1, int $end = 1000): self
    {
        return $this->state(fn (): array => [
            'atp_number' => 'ATP-'.fake()->numerify('##########'),
            'permit_issued_at' => now()->subMonths(2)->toDateString(),
            'serial_start' => $start,
            'serial_end' => $end,
            'next_number' => $start,
        ]);
    }

    /** A series with only one number left before the range is exhausted. */
    public function nearlyExhausted(): self
    {
        return $this->withAuthority(1, 10)->state(fn (): array => ['next_number' => 10]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
