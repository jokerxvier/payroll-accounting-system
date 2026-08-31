<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\ContactRequest;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin surface for `pas_contacts` — Phase 5 Slice 4.
 *
 * Creating and editing happen in a sheet on the index, per `RULES.md` §807
 * and the same call made for the chart of accounts: a contact is one record
 * with a handful of fields, which is exactly what a sheet suits. So there are
 * no `create` / `edit` pages, and the index ships everything the sheet needs
 * up front.
 *
 * The list paginates and searches, unlike the small catalogs. A school's
 * contact register grows with its intake — it is closer in size to the
 * journal than to the tax-rate table.
 */
final class ContactController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Contact::class);

        $search = trim((string) $request->query('search', ''));
        $role = (string) $request->query('role', '');

        $contacts = Contact::query()
            ->with(['receivableAccount:id,code,name', 'payableAccount:id,code,name'])
            ->matching($search)
            ->when($role === 'customer', fn ($query) => $query->customers())
            ->when($role === 'supplier', fn ($query) => $query->suppliers())
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Contact $contact): array => $this->summarise($contact));

        return Inertia::render('admin/accounting/contacts/index', [
            'contacts' => $contacts,
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'role' => in_array($role, ['customer', 'supplier'], true) ? $role : null,
            ],
            // Shipped with the list so the sheet can populate its pickers
            // without a second request when it opens.
            'receivableAccountOptions' => $this->accountOptions(ChartOfAccount::TYPE_ASSET),
            'payableAccountOptions' => $this->accountOptions(ChartOfAccount::TYPE_LIABILITY),
            'can' => [
                'create' => Gate::allows('create', Contact::class),
            ],
        ]);
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        Gate::authorize('create', Contact::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $contact = DB::transaction(fn (): Contact => Contact::create($data));

        // Back where the sheet was opened, not always the register: the same
        // sheet now creates a customer from the invoice form, and sending
        // that operator to the contacts list would throw away the draft they
        // were part way through. From the register itself `back()` is the
        // register, filters and all.
        return redirect()
            ->back(fallback: route('admin.contacts.index'))
            ->with('success', "Contact '{$contact->name}' created.");
    }

    public function update(ContactRequest $request, Contact $contact): RedirectResponse
    {
        Gate::authorize('update', $contact);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        DB::transaction(fn () => $contact->update($data));

        return redirect()
            ->route('admin.contacts.index')
            ->with('success', "Contact '{$contact->name}' updated.");
    }

    /**
     * Delete a contact.
     *
     * Nothing references contacts yet — Slice 5's invoices and bills will,
     * with a restrictOnDelete foreign key. The soft-block goes in now so that
     * FK lands in a controller that already refuses gracefully and points at
     * deactivating, rather than surfacing a raw SQL error the first time an
     * operator tries to remove a contact that has been invoiced.
     */
    public function destroy(Contact $contact): RedirectResponse
    {
        Gate::authorize('delete', $contact);

        if ($this->isReferenced($contact)) {
            return redirect()
                ->route('admin.contacts.index')
                ->with('error', "Cannot delete '{$contact->name}' — documents reference it. Mark the contact inactive instead, so its history stays readable.");
        }

        try {
            DB::transaction(fn () => $contact->delete());
        } catch (QueryException $e) {
            // The foreign key is the real guarantee; the check above is the
            // friendly one. A document created between the two would
            // otherwise surface as an unhandled integrity violation.
            report($e);

            return redirect()
                ->route('admin.contacts.index')
                ->with('error', "Cannot delete '{$contact->name}' — something now references it. Mark the contact inactive instead.");
        }

        return redirect()
            ->route('admin.contacts.index')
            ->with('success', "Contact '{$contact->name}' deleted.");
    }

    /**
     * Whether any document points at this contact.
     *
     * This was a stub returning `false` from Slice 4 to Slice 11, waiting for
     * the documents that would fill it in. They arrived in Slice 5 and nobody
     * came back — so the refusal path below it, already written and worded,
     * never once fired, and deleting an invoiced contact hit the
     * `restrictOnDelete` foreign key as an unhandled 500 instead.
     *
     * Invoices and payments only. `pas_contact_students` cascades on purpose:
     * a link to a student has no meaning without the payer, and it is not
     * financial history worth preserving on its own.
     */
    private function isReferenced(Contact $contact): bool
    {
        return Invoice::query()->where('contact_id', $contact->getKey())->exists()
            || Payment::query()->where('contact_id', $contact->getKey())->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Contact $contact): array
    {
        return [
            ...$contact->only([
                'id', 'code', 'name', 'is_customer', 'is_supplier',
                'tin', 'email', 'phone', 'address',
                'receivable_account_id', 'payable_account_id',
                'lms_student_id', 'is_active', 'notes',
            ]),
            'receivable_account' => $contact->receivableAccount
                ? $contact->receivableAccount->only(['id', 'code', 'name'])
                : null,
            'payable_account' => $contact->payableAccount
                ? $contact->payableAccount->only(['id', 'code', 'name'])
                : null,
            // Per-row permissions, with the legality check first and the
            // policy second. A contact has no immutable state today, so
            // nothing gates on it yet — but the ordering is the convention
            // that stops Gate::before's platform-admin short-circuit from
            // offering illegal controls, which is the hole fixed in 9c8e385.
            // The next model here with real state inherits it for free.
            'can' => [
                'update' => Gate::allows('update', $contact),
                // State first, then policy — the same ordering PaymentController
                // uses, and for the same reason: `Gate::before` grants a
                // platform admin every ability and would sail straight past a
                // check that lived only in the policy.
                'delete' => ! $this->isReferenced($contact)
                    && Gate::allows('delete', $contact),
            ],
        ];
    }

    /**
     * Accounts offered as a control-account override, narrowed by type.
     *
     * A receivable is an asset and a payable is a liability; offering the
     * other side is always a mistake, so the picker does not present it.
     * Same narrowing TaxRateController applies to its VAT accounts.
     *
     * @return Collection<int, ChartOfAccount>
     */
    private function accountOptions(string $type): Collection
    {
        return ChartOfAccount::query()
            ->active()
            ->ofType($type)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);
    }
}
