<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Imports\ContactImport;
use App\Models\Pas\Contact;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Applies a parsed contact worksheet: creates what is new, updates what moved.
 *
 * Matched on `code`, which {@see ContactImport} has already
 * resolved to a contact id where one exists. Rows the preview marked
 * `unchanged` are skipped rather than saved — touching them would bump
 * `updated_at` on a register a person is about to audit, and make the audit
 * log claim work that did not happen.
 *
 * One transaction for the file. A contact register half-applied is worse than
 * one not applied at all: the operator cannot tell which half, and the natural
 * response — upload it again — is only safe because matching on code makes the
 * whole thing idempotent. Keeping it atomic means they never have to reason
 * about that.
 *
 * `lms_parent_id` and `lms_student_id` are never written here. They are the
 * guardian import's dedupe key, and rewiring a contact to a different family
 * through a spreadsheet is not something to make easy.
 */
final class ApplyContactImport
{
    /**
     * @param  array<int, array<string, mixed>>  $parsed  Rows from `ContactImport::parsed()`.
     * @return array{created: int, updated: int, unchanged: int}
     *
     * @throws DomainException When any row still carries an error.
     */
    public function execute(array $parsed): array
    {
        $withErrors = array_filter(
            $parsed,
            static fn (array $row): bool => ! empty($row['errors']),
        );

        if ($withErrors !== []) {
            throw new DomainException(sprintf(
                '%d row%s still need fixing.',
                count($withErrors),
                count($withErrors) === 1 ? '' : 's',
            ));
        }

        return DB::transaction(function () use ($parsed): array {
            $created = 0;
            $updated = 0;
            $unchanged = 0;

            foreach ($parsed as $row) {
                $action = $row['action'] ?? null;

                /** @var array<string, mixed> $attributes */
                $attributes = $row['attributes'] ?? [];

                if ($action === 'unchanged') {
                    $unchanged++;

                    continue;
                }

                if ($action === 'create') {
                    Contact::create([
                        'code' => $row['code'],
                        ...$attributes,
                    ]);
                    $created++;

                    continue;
                }

                $contactId = $row['contact_id'] ?? null;

                if (! is_int($contactId)) {
                    continue;
                }

                $contact = Contact::query()->find($contactId);

                if ($contact === null) {
                    // Deleted between preview and confirm. Skipped rather than
                    // recreated: the code was matched to a row that somebody
                    // has since removed, and resurrecting it silently would
                    // undo a deliberate deletion.
                    continue;
                }

                $contact->update($attributes);
                $updated++;
            }

            return [
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $unchanged,
            ];
        });
    }
}
