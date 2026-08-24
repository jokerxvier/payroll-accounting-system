<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\ChartOfAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChartOfAccount>
 */
class ChartOfAccountFactory extends Factory
{
    protected $model = ChartOfAccount::class;

    /**
     * Default: an active, unlocked expense account.
     *
     * `normal_balance` is derived rather than hard-coded so a state that
     * changes `type` without also setting `normal_balance` still produces a
     * coherent row — see {@see self::ofType()}.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => (string) fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->words(2, true),
            'type' => ChartOfAccount::TYPE_EXPENSE,
            'subtype' => null,
            'normal_balance' => ChartOfAccount::normalBalanceForType(ChartOfAccount::TYPE_EXPENSE),
            'cash_flow_category' => ChartOfAccount::CASH_FLOW_OPERATING,
            'parent_id' => null,
            'system_code' => null,
            'description' => null,
            'is_active' => true,
            'is_locked' => false,
        ];
    }

    /**
     * Switch the account type, keeping `normal_balance` consistent with it.
     */
    public function ofType(string $type): self
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'normal_balance' => ChartOfAccount::normalBalanceForType($type),
        ]);
    }

    public function asset(): self
    {
        return $this->ofType(ChartOfAccount::TYPE_ASSET);
    }

    public function liability(): self
    {
        return $this->ofType(ChartOfAccount::TYPE_LIABILITY);
    }

    public function equity(): self
    {
        return $this->ofType(ChartOfAccount::TYPE_EQUITY);
    }

    public function income(): self
    {
        return $this->ofType(ChartOfAccount::TYPE_INCOME);
    }

    public function expense(): self
    {
        return $this->ofType(ChartOfAccount::TYPE_EXPENSE);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * A system account — one the software posts to on its own. Locked, so
     * the UI refuses to delete or re-code it.
     */
    public function system(string $systemCode): self
    {
        return $this->state(fn (): array => [
            'system_code' => $systemCode,
            'is_locked' => true,
        ]);
    }

    public function cashFlow(string $category): self
    {
        return $this->state(fn (): array => ['cash_flow_category' => $category]);
    }
}
