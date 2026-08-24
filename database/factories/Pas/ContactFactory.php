<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * Default: an active customer with no account overrides, which is the
     * common case — most contacts post through the school's AR_CONTROL.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('CON???'),
            'name' => fake()->company(),
            'is_customer' => true,
            'is_supplier' => false,
            'tin' => null,
            'email' => null,
            'phone' => null,
            'address' => null,
            'receivable_account_id' => null,
            'payable_account_id' => null,
            'lms_student_id' => null,
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function customer(): self
    {
        return $this->state(fn (): array => [
            'is_customer' => true,
            'is_supplier' => false,
        ]);
    }

    public function supplier(): self
    {
        return $this->state(fn (): array => [
            'is_customer' => false,
            'is_supplier' => true,
        ]);
    }

    /** A counterparty the school both bills and buys from. */
    public function both(): self
    {
        return $this->state(fn (): array => [
            'is_customer' => true,
            'is_supplier' => true,
        ]);
    }

    public function withTin(string $tin = '123-456-789-000'): self
    {
        return $this->state(fn (): array => ['tin' => $tin]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
