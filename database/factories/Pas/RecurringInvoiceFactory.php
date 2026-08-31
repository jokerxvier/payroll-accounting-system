<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\RecurringInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringInvoice>
 */
class RecurringInvoiceFactory extends Factory
{
    protected $model = RecurringInvoice::class;

    /**
     * Default: a monthly sales schedule due on the 1st, already due today.
     *
     * `next_run_on` matches `starts_on` so a freshly made schedule generates
     * on the first run — a factory whose default produced nothing would make
     * every generator test start by fixing the fixture.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Tuition — '.fake()->lastName().' family',
            'type' => Invoice::TYPE_SALES,
            'contact_id' => Contact::factory()->state(['is_customer' => true]),
            'lms_student_id' => null,
            'student_name' => null,
            'reference' => null,
            'is_vat_inclusive' => false,
            'notes' => null,
            'terms' => null,
            'frequency' => RecurringInvoice::FREQUENCY_MONTHLY,
            'day_of_month' => 1,
            'starts_on' => '2026-08-01',
            'ends_on' => null,
            'next_run_on' => '2026-08-01',
            'due_days' => 15,
            'is_active' => true,
        ];
    }

    public function paused(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function quarterly(): self
    {
        return $this->state(fn (): array => [
            'frequency' => RecurringInvoice::FREQUENCY_QUARTERLY,
        ]);
    }
}
