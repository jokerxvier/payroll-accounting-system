<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Accounting\PostJournalEntry;
use App\Actions\Accounting\ReverseJournalEntry;
use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Multitenancy\Models\Tenant;
use Throwable;

/**
 * Demo journal entries for the default school — a small but realistic set of
 * books to develop and demo the ledger against.
 *
 * Local only. It writes fabricated financial records, so it refuses to run
 * in production outright rather than relying on DatabaseSeeder's environment
 * gate alone.
 *
 * Entries are posted through {@see PostJournalEntry} and corrected through
 * {@see ReverseJournalEntry}, not inserted as rows. Hand-built fixtures would
 * skip the balance assertion and the period guard, so a typo in this file
 * could seed a ledger that the application itself would have refused to
 * create — the worst kind of demo data, because everything downstream then
 * disagrees for reasons that look like bugs elsewhere.
 *
 * Tenancy: the seeder calls `makeCurrent()` on the default school rather
 * than passing `school_id` around. The models are all `BelongsToTenant`, so
 * with a current tenant the auto-fill and the global scope both do the right
 * thing, and the actions can be used exactly as a request would use them.
 * This is the fix the {@see DatabaseSeeder} docblock proposes for the demo
 * seeders that are broken for want of it. The tenant is restored afterwards.
 *
 * Idempotent: every entry carries a stable `DEMO-*` reference, and the
 * seeder no-ops entirely if any of them already exist. Re-posting would
 * duplicate the books rather than refresh them, so "already seeded" means
 * "leave it alone".
 */
final class DemoJournalSeeder extends Seeder
{
    /**
     * Amounts are integer centavos. Each entry balances; the seeder asserts
     * that again through the posting action, so a mistake here fails loudly
     * at seed time instead of producing crooked books.
     *
     * @var list<array{reference: string, narration: string, day: int, lines: list<array{account: string, debit?: int, credit?: int, memo?: string}>}>
     */
    private const ENTRIES = [
        [
            'reference' => 'DEMO-001',
            'narration' => 'Tuition collected in cash, VAT inclusive',
            'day' => 3,
            'lines' => [
                ['account' => '1100', 'debit' => 28_000_000, 'memo' => 'Cash received'],
                ['account' => '4100', 'credit' => 25_000_000, 'memo' => 'Tuition fees'],
                ['account' => '2200', 'credit' => 3_000_000, 'memo' => 'Output VAT at 12%'],
            ],
        ],
        [
            'reference' => 'DEMO-002',
            'narration' => 'Salary accrual for the period',
            'day' => 5,
            'lines' => [
                ['account' => '5100', 'debit' => 18_000_000, 'memo' => 'Gross salaries'],
                ['account' => '2340', 'credit' => 1_500_000, 'memo' => 'Withholding tax'],
                ['account' => '2300', 'credit' => 16_500_000, 'memo' => 'Net pay owed'],
            ],
        ],
        [
            'reference' => 'DEMO-003',
            'narration' => 'Monthly rent paid by cheque',
            'day' => 7,
            'lines' => [
                ['account' => '5200', 'debit' => 4_500_000],
                ['account' => '1110', 'credit' => 4_500_000, 'memo' => 'Cheque 100241'],
            ],
        ],
        [
            'reference' => 'DEMO-004',
            'narration' => 'Utilities billed, not yet paid',
            'day' => 9,
            'lines' => [
                ['account' => '5210', 'debit' => 1_250_000],
                ['account' => '2100', 'credit' => 1_250_000, 'memo' => 'Meralco'],
            ],
        ],
        [
            'reference' => 'DEMO-005',
            'narration' => 'Settled the utilities payable',
            'day' => 14,
            'lines' => [
                ['account' => '2100', 'debit' => 1_250_000],
                ['account' => '1110', 'credit' => 1_250_000],
            ],
        ],
    ];

    /**
     * Posted, then reversed — so the demo books show what a correction
     * actually looks like: two entries that offset, both still on the books.
     *
     * @var array{reference: string, narration: string, day: int, reason: string, lines: list<array{account: string, debit?: int, credit?: int}>}
     */
    private const REVERSED_ENTRY = [
        'reference' => 'DEMO-006',
        'narration' => 'Office supplies — keyed against the wrong account',
        'day' => 16,
        'reason' => 'Posted to supplies instead of repairs',
        'lines' => [
            ['account' => '5220', 'debit' => 800_000],
            ['account' => '1110', 'credit' => 800_000],
        ],
    ];

    /**
     * Left unposted, so the journal has a draft to show the post and delete
     * actions against.
     *
     * @var array{reference: string, narration: string, day: int, lines: list<array{account: string, debit?: int, credit?: int}>}
     */
    private const DRAFT_ENTRY = [
        'reference' => 'DEMO-007',
        'narration' => 'Repairs accrual — awaiting the supplier invoice',
        'day' => 20,
        'lines' => [
            ['account' => '5230', 'debit' => 1_800_000],
            ['account' => '2100', 'credit' => 1_800_000],
        ],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('DemoJournalSeeder refuses to run in production — it writes fabricated financial records.');

            return;
        }

        $school = School::query()->withoutGlobalScopes()->where('slug', 'default')->first();

        if ($school === null) {
            $this->command?->warn('DemoJournalSeeder skipped: no default school. Run SchoolSeeder first.');

            return;
        }

        $actor = $this->resolveActor();

        if ($actor === null) {
            $this->command?->warn('DemoJournalSeeder skipped: no user to attribute postings to. Run PlatformAdminSeeder first.');

            return;
        }

        $previous = Tenant::current();
        $school->makeCurrent();

        try {
            $this->seedFor($actor);
        } finally {
            // Restore whatever the caller had bound, so a seeder run inside a
            // larger process does not leave the tenant pointing somewhere
            // unexpected.
            if ($previous instanceof School) {
                $previous->makeCurrent();
            } else {
                Tenant::forgetCurrent();
            }
        }
    }

    private function seedFor(User $actor): void
    {
        if (JournalEntry::query()->where('reference', 'like', 'DEMO-%')->exists()) {
            $this->command?->info('DemoJournalSeeder skipped: demo entries already present.');

            return;
        }

        if (ChartOfAccount::query()->count() === 0) {
            $this->command?->warn('DemoJournalSeeder skipped: no chart of accounts. Run AccountingCatalogSeeder first.');

            return;
        }

        $month = CarbonImmutable::now()->startOfMonth();
        $this->ensurePeriodFor($month);

        $poster = app(PostJournalEntry::class);
        $posted = 0;

        foreach (self::ENTRIES as $spec) {
            $entry = $this->buildDraft($spec, $month);

            try {
                $poster->execute($entry, (int) $actor->getKey());
                $posted++;
            } catch (Throwable $e) {
                // Surface rather than swallow: a demo entry that will not
                // post means this file describes books the application
                // considers invalid, which is worth fixing here.
                $this->command?->error(sprintf(
                    'DemoJournalSeeder could not post %s: %s',
                    $spec['reference'],
                    $e->getMessage(),
                ));
            }
        }

        $this->seedReversedPair($poster, $actor, $month);
        $this->buildDraft(self::DRAFT_ENTRY, $month);

        $this->command?->info(sprintf(
            'DemoJournalSeeder: %d posted, 1 reversed pair, 1 draft.',
            $posted,
        ));
    }

    /**
     * Post an entry and then reverse it, so the books carry a worked example
     * of a correction.
     */
    private function seedReversedPair(
        PostJournalEntry $poster,
        User $actor,
        CarbonImmutable $month,
    ): void {
        $entry = $this->buildDraft(self::REVERSED_ENTRY, $month);

        try {
            $original = $poster->execute($entry, (int) $actor->getKey());

            app(ReverseJournalEntry::class)->execute(
                $original,
                (int) $actor->getKey(),
                null,
                self::REVERSED_ENTRY['reason'],
            );
        } catch (Throwable $e) {
            $this->command?->error('DemoJournalSeeder could not seed the reversed pair: '.$e->getMessage());
        }
    }

    /**
     * @param  array{reference: string, narration: string, day: int, lines: list<array{account: string, debit?: int, credit?: int, memo?: string}>}  $spec
     */
    private function buildDraft(array $spec, CarbonImmutable $month): JournalEntry
    {
        $entry = JournalEntry::create([
            'date' => $month->addDays($spec['day'] - 1),
            'reference' => $spec['reference'],
            'narration' => $spec['narration'],
            'status' => JournalEntry::STATUS_DRAFT,
        ]);

        foreach (array_values($spec['lines']) as $index => $line) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->getKey(),
                'line_number' => $index + 1,
                'account_id' => $this->accountId($line['account']),
                'debit_centavos' => $line['debit'] ?? 0,
                'credit_centavos' => $line['credit'] ?? 0,
                'description' => $line['memo'] ?? null,
            ]);
        }

        return $entry->fresh();
    }

    /**
     * Make sure the month the demo entries fall in is open, or none of them
     * can post.
     */
    private function ensurePeriodFor(CarbonImmutable $month): void
    {
        $existing = AccountingPeriod::query()->covering($month)->first();

        if ($existing !== null) {
            if ($existing->isClosed()) {
                $this->command?->warn(sprintf(
                    'DemoJournalSeeder: period %s is closed, so the demo entries will not post. Reopen it and re-run.',
                    $existing->code,
                ));
            }

            return;
        }

        AccountingPeriod::create([
            'code' => $month->format('Y-m'),
            'name' => $month->format('F Y'),
            'start_date' => $month,
            'end_date' => $month->endOfMonth(),
            'fiscal_year' => (int) $month->format('Y'),
            'status' => AccountingPeriod::STATUS_OPEN,
        ]);
    }

    private function accountId(string $code): int
    {
        $id = ChartOfAccount::query()->where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException(sprintf(
                "DemoJournalSeeder expects account '%s' in the chart. Run AccountingCatalogSeeder first.",
                $code,
            ));
        }

        return (int) $id;
    }

    /**
     * Someone to attribute the postings to.
     *
     * Prefers a ledger-capable role so the demo data looks like it was made
     * by the person who would really have made it, then falls back to any
     * user — `posted_by_user_id` is a foreign key, so it has to resolve.
     */
    private function resolveActor(): ?User
    {
        foreach (['accountant', 'super-admin', 'platform-admin'] as $role) {
            $user = User::query()->whereHas(
                'roles',
                fn ($query) => $query->where('name', $role),
            )->first();

            if ($user !== null) {
                return $user;
            }
        }

        return User::query()->first();
    }
}
