<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Reads a CSV into the contact base.
 *
 * Preview and commit run the *same* classification over the same file, so the
 * breakdown shown before the upload is the breakdown that happens. Two code
 * paths would eventually disagree and the operator would approve one import and
 * get another — the same reasoning that put AudienceResolver behind both the
 * contact export and the campaign send.
 *
 * Nothing here creates a Customer. That is the whole feature: an imported number
 * is a marketing asset until it buys food, and the only thing that can promote
 * it is an order.
 */
class ContactImporter
{
    /** Rows returned to the operator to eyeball before committing. */
    private const PREVIEW_ROWS = 10;

    public function __construct(
        private readonly ContactCsvParser $parser,
        private readonly CustomerPhoneIndex $phoneIndex,
    ) {}

    /**
     * What this file would do, without doing it.
     *
     * @param  int|null  $nameColumn  Operator's override, or null to use the guess
     * @param  int|null  $phoneColumn  Operator's override, or null to use the guess
     */
    public function preview(UploadedFile $file, ?int $nameColumn = null, ?int $phoneColumn = null): array
    {
        $parsed = $this->parser->parse($file);

        $nameColumn ??= $parsed['name_column'];
        $phoneColumn ??= $parsed['phone_column'];

        if ($phoneColumn === null) {
            return [
                'headers' => $parsed['headers'],
                'has_header' => $parsed['has_header'],
                'name_column' => $nameColumn,
                'phone_column' => null,
                'total_rows' => count($parsed['rows']),
                'truncated' => $parsed['truncated'],
                'counts' => ['new' => 0, 'already_customer' => 0, 'existing_contact' => 0, 'duplicate_in_file' => 0, 'invalid' => 0],
                'sample' => [],
                'invalid_sample' => [],
                'error' => $parsed['rows'] === []
                    ? 'That file has no rows in it.'
                    : 'No column in that file looks like a Ghana phone number. Pick the phone column, or re-export it with the numbers as text.',
            ];
        }

        $classified = $this->classify($parsed['rows'], $nameColumn, $phoneColumn);

        return [
            'headers' => $parsed['headers'],
            'has_header' => $parsed['has_header'],
            'name_column' => $nameColumn,
            'phone_column' => $phoneColumn,
            'total_rows' => count($parsed['rows']),
            'truncated' => $parsed['truncated'],
            'counts' => $classified['counts'],

            // The first few contacts as they would be stored — normalised
            // number, resolved name, and what will happen to each. An operator
            // who can see 0241234567 become +233241234567 trusts the other
            // 27,999 rows; one who cannot, should not.
            'sample' => array_slice($classified['rows'], 0, self::PREVIEW_ROWS),

            // Rejected rows, verbatim. Without these "412 invalid" is a number
            // with no remedy attached; with them it is usually one obvious
            // problem repeated 412 times.
            'invalid_sample' => array_slice($classified['invalid'], 0, self::PREVIEW_ROWS),
        ];
    }

    /**
     * Commit the file.
     *
     * @throws \RuntimeException when there is nothing importable in it
     */
    public function import(
        UploadedFile $file,
        string $label,
        ?string $sourceNote,
        User $user,
        ?int $nameColumn = null,
        ?int $phoneColumn = null,
    ): ContactImport {
        $parsed = $this->parser->parse($file);

        $nameColumn ??= $parsed['name_column'];
        $phoneColumn ??= $parsed['phone_column'];

        if ($phoneColumn === null) {
            throw new \RuntimeException('No column in that file looks like a Ghana phone number.');
        }

        $classified = $this->classify($parsed['rows'], $nameColumn, $phoneColumn);

        $importable = array_values(array_filter(
            $classified['rows'],
            fn (array $row) => in_array($row['outcome'], ['new', 'already_customer'], true),
        ));

        if ($importable === []) {
            throw new \RuntimeException($this->nothingToImportMessage($classified['counts']));
        }

        return DB::transaction(function () use ($importable, $classified, $parsed, $file, $label, $sourceNote, $user) {
            $import = ContactImport::create([
                'label' => $label,
                'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                'source_note' => $sourceNote,
                'uploaded_by_user_id' => $user->id,
                'total_rows' => count($parsed['rows']),
                'imported_count' => count($importable),
                'duplicate_count' => $classified['counts']['duplicate_in_file'] + $classified['counts']['existing_contact'],
                'invalid_count' => $classified['counts']['invalid'],
                'already_customer_count' => $classified['counts']['already_customer'],
            ]);

            $now = now();

            foreach (array_chunk($importable, 500) as $chunk) {
                $insert = [];

                foreach ($chunk as $row) {
                    $insert[] = [
                        'name' => $row['name'],
                        'phone' => $row['phone'],
                        'source' => 'import',
                        'contact_import_id' => $import->id,

                        // An existing customer is stamped with the date of the
                        // order that made them one, never with today. Writing
                        // today here would let a list of long-standing customers
                        // read as a list that acquired them.
                        'converted_at' => $row['converted_at'],
                        'converted_order_id' => $row['converted_order_id'],
                        'customer_id' => $row['customer_id'],
                        'was_customer_before_import' => $row['outcome'] === 'already_customer',

                        'metadata' => $row['metadata'] === [] ? null : json_encode($row['metadata']),
                        'created_by_user_id' => $user->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                Contact::insert($insert);
            }

            activity('admin')
                ->causedBy($user)
                ->performedOn($import)
                ->event('contacts_imported')
                ->withProperties([
                    'label' => $label,
                    'imported' => $import->imported_count,
                    'already_customer' => $import->already_customer_count,
                    'duplicate' => $import->duplicate_count,
                    'invalid' => $import->invalid_count,
                ])
                ->log('Imported '.$import->imported_count.' contacts ('.$label.')');

            return $import;
        });
    }

    /**
     * Say why there was nothing to import, in terms of what was in the file.
     *
     * "Nothing in that file is new" on its own sends the operator back to a
     * spreadsheet with no idea what to change. Which of the three reasons it was
     * is the entire content of the message.
     */
    private function nothingToImportMessage(array $counts): string
    {
        if ($counts['existing_contact'] > 0) {
            return 'Nothing in that file is new. '.$counts['existing_contact']
                .' of its numbers are already in the contact base.';
        }

        if ($counts['invalid'] > 0) {
            return 'None of the '.$counts['invalid'].' numbers in that file could be read as a Ghana '
                .'mobile number. If the column came out of Excel, re-export it with the numbers as text.';
        }

        return 'There is nothing importable in that file.';
    }

    /**
     * Undo an import.
     *
     * Only removes the contacts it created that have NOT since ordered. A
     * converted contact is the record of where a real customer came from, and
     * deleting it to tidy up a bad upload would erase the one piece of
     * attribution the whole feature exists to produce. The batch row stays
     * either way, so the counts survive the undo.
     *
     * Force-deleted rather than soft-deleted, and that is not a detail. The
     * phone column is uniquely indexed and the index does not know about
     * `deleted_at`, so soft-deleted rows still block a re-import — and undo
     * exists precisely for the case where somebody mapped the wrong column and
     * wants to upload the same file again. A soft delete would refuse the
     * correction as a duplicate of the mistake.
     *
     * @return int how many were removed
     */
    public function undo(ContactImport $import, User $user): int
    {
        $removed = $import->contacts()->unconverted()->forceDelete();

        activity('admin')
            ->causedBy($user)
            ->performedOn($import)
            ->event('contact_import_undone')
            ->withProperties(['removed' => $removed, 'kept' => $import->contacts()->converted()->count()])
            ->log('Undid contact import ('.$import->label.'): removed '.$removed);

        return $removed;
    }

    /**
     * Decide what happens to every row.
     *
     * Order of precedence matters and is deliberate: invalid, then duplicated
     * within the file, then already a contact, then already a customer, then
     * new. Each test is cheaper and more certain than the one after it.
     *
     * @return array{rows: array<int, array>, invalid: array<int, array>, counts: array<string, int>}
     */
    private function classify(array $rows, ?int $nameColumn, int $phoneColumn): array
    {
        $existing = $this->existingContactPhones();
        $customers = $this->phoneIndex->build();

        $seen = [];
        $out = [];
        $invalid = [];

        $counts = [
            'new' => 0,
            'already_customer' => 0,
            'existing_contact' => 0,
            'duplicate_in_file' => 0,
            'invalid' => 0,
        ];

        foreach ($rows as $lineNumber => $row) {
            $rawPhone = trim((string) ($row[$phoneColumn] ?? ''));
            $phone = PhoneNormaliser::normalise($rawPhone);

            if ($phone === null) {
                $counts['invalid']++;
                $invalid[] = [
                    'line' => $lineNumber + 1,
                    'value' => $rawPhone,
                    'reason' => $rawPhone === '' ? 'No number in that column' : 'Not a Ghana mobile number',
                ];

                continue;
            }

            if (isset($seen[$phone])) {
                $counts['duplicate_in_file']++;

                continue;
            }

            $seen[$phone] = true;

            if (isset($existing[$phone])) {
                $counts['existing_contact']++;
                $out[] = [
                    'name' => $this->nameFrom($row, $nameColumn),
                    'phone' => $phone,
                    'raw_phone' => $rawPhone,
                    'outcome' => 'existing_contact',
                    'converted_at' => null,
                    'converted_order_id' => null,
                    'customer_id' => null,
                    'metadata' => [],
                ];

                continue;
            }

            $order = $customers[$phone] ?? null;

            if ($order !== null) {
                $counts['already_customer']++;
            } else {
                $counts['new']++;
            }

            $out[] = [
                'name' => $this->nameFrom($row, $nameColumn),
                'phone' => $phone,
                'raw_phone' => $rawPhone,
                'outcome' => $order !== null ? 'already_customer' : 'new',
                'converted_at' => $order['ordered_at'] ?? null,
                'converted_order_id' => $order['order_id'] ?? null,
                'customer_id' => $order['customer_id'] ?? null,
                'metadata' => $this->extraColumns($row, $nameColumn, $phoneColumn),
            ];
        }

        return ['rows' => $out, 'invalid' => $invalid, 'counts' => $counts];
    }

    /**
     * Phones already in the contact base, including soft-deleted ones.
     *
     * Soft-deleted rows count as present because the phone column is uniquely
     * indexed and the index does not know about deleted_at — re-importing a
     * number somebody removed would hit a constraint violation halfway through a
     * batch insert rather than being reported as a duplicate.
     *
     * @return array<string, true>
     */
    private function existingContactPhones(): array
    {
        $map = [];

        Contact::withTrashed()
            ->select('phone')
            ->chunk(2000, function ($contacts) use (&$map): void {
                foreach ($contacts as $contact) {
                    $map[$contact->phone] = true;
                }
            });

        return $map;
    }

    private function nameFrom(array $row, ?int $nameColumn): ?string
    {
        if ($nameColumn === null) {
            return null;
        }

        $name = trim((string) ($row[$nameColumn] ?? ''));

        return $name === '' ? null : mb_substr($name, 0, 255);
    }

    /**
     * Everything in the row that was not mapped, kept verbatim.
     *
     * A list usually carries more than a name and a number — a location, a
     * signup date, which event it came from. None of it is used today and
     * throwing it away is irreversible, so it is stored as-is against the
     * contact.
     */
    private function extraColumns(array $row, ?int $nameColumn, int $phoneColumn): array
    {
        $extra = [];

        foreach ($row as $index => $value) {
            if ($index === $nameColumn || $index === $phoneColumn) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $extra[(string) $index] = mb_substr($value, 0, 500);
            }
        }

        return $extra;
    }
}
