<?php

declare(strict_types=1);

namespace App\Actions\Contacts;

use App\Models\Lms\Guardian;
use App\Models\Lms\Student;
use App\Models\Pas\Contact;
use App\Models\Pas\ContactStudent;
use Illuminate\Support\Facades\DB;

/**
 * Walks the school's active student roster and brings each student's payer
 * into the contact register, linked to the student they pay for.
 *
 * The import is oriented around students — one preview row per student — so
 * that every enrolled student is visible to the person confirming it. A
 * student whose parent record is missing appears as a row with an error and
 * is refused, rather than silently dropped from a payer-grouped list where
 * nobody would notice the absence.
 *
 * **Students are never contacts.** The contact created for a row is the
 * student's parent or guardian: parents are the payers, students are who the
 * charges are for, and `pas_contact_students` is the edge between them.
 *
 * **The rule this class exists to hold: one payer, one contact.** A parent
 * with three children appears on three rows and gets one contact and three
 * links — never three copies of the same person. Duplicating a payer scatters
 * a family's receivable across several counterparties, breaks their statement,
 * and counts them repeatedly in Aged Receivables, so every row either finds
 * the existing contact or creates exactly one. `apply()` re-resolves the
 * payer per row inside the transaction so the second sibling finds the
 * contact the first sibling's row just created.
 *
 * De-duplication runs in confidence order:
 *
 *   1. `(school_id, lms_parent_id)` — the source row's own id. The only key
 *      that is a fact rather than a guess, and what makes re-running the
 *      import a no-op.
 *   2. `email`, then 3. `phone` — heuristics, needed because the LMS does not
 *      guarantee siblings share a parent row. The demo data has
 *      `sm_students.id == sm_parents.id` with consecutive user ids, which is
 *      the signature of a parent record created per admission; assuming the
 *      LMS already deduped would produce exactly the duplication above.
 *
 * **Ambiguity is refused, never guessed.** Two existing contacts sharing one
 * email is a row error: merging the wrong two people into one payer is far
 * harder to unpick than importing nothing and being told why.
 *
 * `preview()` writes nothing — it is what the confirm screen renders. `apply()`
 * is the only method that touches the database.
 */
final class ImportLmsStudents
{
    public const ACTION_CREATE = 'create';

    public const ACTION_LINK = 'link';

    public const ACTION_UNCHANGED = 'unchanged';

    /**
     * What importing would do, without doing any of it. One row per active
     * student, sorted by student name.
     *
     * @return list<array<string, mixed>>
     */
    public function preview(): array
    {
        $students = Student::query()->active()->get();

        if ($students->isEmpty()) {
            return [];
        }

        $guardians = Guardian::query()
            ->whereIn('id', $students->pluck('parent_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($students as $student) {
            $guardian = $student->parent_id === null ? null : $guardians->get((int) $student->parent_id);

            $rows[] = $this->describe($student, $guardian instanceof Guardian ? $guardian : null);
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['student_name'], (string) $b['student_name']));

        return $rows;
    }

    /**
     * Apply a previewed set. Rows carrying errors are skipped, not guessed at.
     *
     * `contacts_linked` counts each existing contact once however many of its
     * children are on the list, and never counts a contact this run created.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{contacts_created: int, contacts_linked: int, students_linked: int}
     */
    public function apply(array $rows): array
    {
        $created = 0;
        $linked = 0;
        $studentsLinked = 0;

        DB::transaction(function () use ($rows, &$created, &$linked, &$studentsLinked): void {
            /** @var array<int, true> $seen */
            $seen = [];

            foreach ($rows as $row) {
                if (! empty($row['errors']) || $row['action'] === self::ACTION_UNCHANGED) {
                    continue;
                }

                $parentId = (int) $row['lms_parent_id'];

                // Resolved per row rather than from the preview, so a sibling
                // later in the list finds the contact an earlier row created.
                $contact = $this->resolveExisting(
                    $parentId,
                    $row['payer_email'] ?? null,
                    $row['payer_phone'] ?? null,
                );

                if ($contact === null) {
                    $contact = Contact::create([
                        'code' => $this->uniqueCode($parentId),
                        'name' => (string) $row['payer_name'],
                        'is_customer' => true,
                        'is_supplier' => false,
                        'email' => $row['payer_email'] ?? null,
                        'phone' => $row['payer_phone'] ?? null,
                        'address' => $row['payer_address'] ?? null,
                        'lms_parent_id' => $parentId,
                        'is_active' => true,
                    ]);

                    $created++;
                    $seen[(int) $contact->getKey()] = true;
                } else {
                    // Found by email or phone rather than by id — claim the
                    // pointer so the next run matches on the certain key.
                    if ($contact->lms_parent_id === null) {
                        $contact->forceFill(['lms_parent_id' => $parentId])->save();
                    }

                    if (! isset($seen[(int) $contact->getKey()])) {
                        $linked++;
                        $seen[(int) $contact->getKey()] = true;
                    }
                }

                $studentsLinked += $this->linkStudent(
                    $contact,
                    (int) $row['lms_student_id'],
                    (string) $row['student_name'],
                    $row['relationship'] ?? null,
                );
            }
        });

        return [
            'contacts_created' => $created,
            'contacts_linked' => $linked,
            'students_linked' => $studentsLinked,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Student $student, ?Guardian $guardian): array
    {
        $parentId = $student->parent_id === null ? null : (int) $student->parent_id;

        $name = $guardian?->billingName();
        $email = $guardian?->billingEmail();
        $phone = $guardian?->billingPhone();

        $errors = [];

        if ($guardian === null) {
            $errors[] = 'No parent or guardian record in the school system, so there is nobody to bill.';
        } elseif ($name === null) {
            $errors[] = 'This parent record has no name on it, so there is nobody to bill.';
        }

        $existing = null;

        if ($guardian !== null && $parentId !== null) {
            try {
                $existing = $this->resolveExisting($parentId, $email, $phone);
            } catch (AmbiguousContactMatch $e) {
                $errors[] = $e->getMessage();
            }
        }

        $lmsStudentId = (int) $student->getKey();

        $action = match (true) {
            $errors !== [] => self::ACTION_UNCHANGED,
            $existing === null => self::ACTION_CREATE,
            $this->alreadyLinked($existing, $lmsStudentId) => self::ACTION_UNCHANGED,
            default => self::ACTION_LINK,
        };

        return [
            'lms_student_id' => $lmsStudentId,
            'student_name' => $student->displayName(),
            'lms_parent_id' => $parentId,
            'payer_name' => $name,
            'payer_email' => $email,
            'payer_phone' => $phone,
            'payer_address' => $guardian?->billingAddress(),
            'relationship' => $guardian?->guardians_relation,
            'existing_contact_id' => $existing?->getKey(),
            'existing_contact_name' => $existing?->name,
            'action' => $action,
            'errors' => $errors,
        ];
    }

    /**
     * The contact this parent already is, if any.
     *
     * @throws AmbiguousContactMatch When a heuristic matches more than one.
     */
    private function resolveExisting(int $parentId, ?string $email, ?string $phone): ?Contact
    {
        $byId = Contact::query()->where('lms_parent_id', $parentId)->first();

        if ($byId !== null) {
            return $byId;
        }

        foreach ([['email', $email], ['phone', $phone]] as [$column, $value]) {
            if ($value === null || $value === '') {
                continue;
            }

            // Deliberately NOT filtered to contacts with no `lms_parent_id`.
            // The case this exists for is two LMS parent rows describing one
            // person — the second row must find the contact the first row
            // already created and claimed, or the sibling gets a duplicate
            // payer, which is the outcome the whole class is built to avoid.
            $matches = Contact::query()
                ->where($column, $value)
                ->get();

            if ($matches->count() > 1) {
                throw new AmbiguousContactMatch(sprintf(
                    '%d contacts already share the %s %s. Merge or correct them first — picking one automatically could bill the wrong person.',
                    $matches->count(),
                    $column,
                    $value,
                ));
            }

            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }

    private function alreadyLinked(Contact $contact, int $lmsStudentId): bool
    {
        return ContactStudent::query()
            ->where('contact_id', $contact->getKey())
            ->forStudent($lmsStudentId)
            ->exists();
    }

    /**
     * @return int 1 if a new link was written, 0 if it already existed.
     */
    private function linkStudent(Contact $contact, int $lmsStudentId, string $studentName, ?string $relationship): int
    {
        $existing = ContactStudent::query()
            ->where('contact_id', $contact->getKey())
            ->forStudent($lmsStudentId)
            ->first();

        if ($existing !== null) {
            return 0;
        }

        // The first payer linked to a student becomes the primary. A second
        // one — a sponsor, a separated parent — is linked but not promoted;
        // changing who pays by default is a decision for a person, not an
        // import.
        $hasPrimary = ContactStudent::query()
            ->forStudent($lmsStudentId)
            ->primary()
            ->exists();

        ContactStudent::create([
            'contact_id' => $contact->getKey(),
            'lms_student_id' => $lmsStudentId,
            'student_name' => $studentName,
            'relationship' => $relationship,
            'is_primary_payer' => ! $hasPrimary,
        ]);

        return 1;
    }

    /**
     * `PAR-{id}`, with a numeric suffix if a human already took that code.
     */
    private function uniqueCode(int $parentId): string
    {
        $base = 'PAR-'.$parentId;
        $code = $base;
        $suffix = 1;

        while (Contact::query()->where('code', $code)->exists()) {
            $code = $base.'-'.$suffix++;
        }

        return $code;
    }
}
