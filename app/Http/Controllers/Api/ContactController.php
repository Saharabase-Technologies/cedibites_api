<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContactImportResource;
use App\Http\Resources\ContactResource;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Services\Contacts\ContactImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The supplementary contact base — numbers we hold that have bought nothing.
 *
 * Sits beside the customer list in the UI and nowhere near it in the data. See
 * the contacts migration for why that separation is the feature rather than a
 * detail of it.
 *
 * Gated on `manage_campaigns`, the same ceiling as the contact export, and for
 * the same reason: the entire content of this table is names and phone numbers
 * in bulk. `view_customers` is held by cashiers, riders and partners so they can
 * look up the person in front of them — it was never meant to hand anybody
 * 28,000 numbers, and the route comment above the export says so.
 */
class ContactController extends Controller
{
    public function __construct(private readonly ContactImporter $importer) {}

    /**
     * The contact base, filtered.
     *
     * Defaults to showing everything rather than only the supplementary rows —
     * hiding converted contacts would make the list look like it was shrinking
     * every time the feature worked.
     */
    public function index(Request $request): JsonResponse
    {
        $contacts = Contact::with('import:id,label')
            ->when($request->filled('import_id'), fn ($q) => $q->where('contact_import_id', $request->integer('import_id')))
            ->when($request->string('status')->toString() === 'supplementary', fn ($q) => $q->unconverted())
            ->when($request->string('status')->toString() === 'converted', fn ($q) => $q->converted())
            ->when($request->string('status')->toString() === 'acquired',
                fn ($q) => $q->converted()->where('was_customer_before_import', false))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = trim($request->string('search')->toString());

                $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                    // Matched on the raw digits too, so pasting 0241234567 from
                    // a WhatsApp message finds +233241234567.
                    ->orWhere('phone', 'like', '%'.preg_replace('/[^\d]/', '', $term).'%'));
            })
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return response()->success(
            ContactResource::collection($contacts)->response()->getData(true),
        );
    }

    /**
     * The headline counts.
     *
     * Served as its own call so the tab can show them without paging through the
     * table, and so the wording that keeps these apart from customer numbers
     * lives in one place.
     */
    public function stats(): JsonResponse
    {
        $total = Contact::count();
        $converted = Contact::converted()->count();
        $acquired = Contact::converted()->where('was_customer_before_import', false);

        return response()->success([
            'total' => $total,

            // The number that must never be added to the customer count.
            'supplementary' => $total - $converted,

            'converted' => $converted,
            'acquired' => (clone $acquired)->count(),
            'already_customer' => Contact::converted()->where('was_customer_before_import', true)->count(),
            'imports' => ContactImport::count(),

            /*
             * The moving figures — what has happened lately, rather than what is
             * true in total.
             *
             * A contact base only tells you something when you watch it change.
             * The totals go up forever and look like progress whatever happens;
             * "eleven converted in the last 7 days" is the number that says
             * whether anything is actually working this month.
             */
            'acquired_last_7_days' => (clone $acquired)->where('converted_at', '>=', now()->subDays(7))->count(),
            'acquired_last_30_days' => (clone $acquired)->where('converted_at', '>=', now()->subDays(30))->count(),
            'median_days_to_convert' => $this->medianDaysToConvert(),
        ]);
    }

    /**
     * The typical wait between landing on a list and first ordering.
     *
     * Median rather than mean, and computed in PHP over the converted rows
     * rather than in SQL. A date difference in SQL is dialect-specific — the
     * same EXTRACT/DATEDIFF problem that makes other tests fail on SQLite while
     * passing on Postgres — and the set being averaged is the converted
     * contacts, which is the small side of this table by construction.
     *
     * The mean is the wrong statistic here: one contact who converts after two
     * years drags it somewhere no individual contact has ever been.
     */
    private function medianDaysToConvert(): ?int
    {
        $days = Contact::converted()
            ->where('was_customer_before_import', false)
            ->get(['created_at', 'converted_at', 'was_customer_before_import'])
            ->map(fn (Contact $c) => $c->daysToConvert())
            ->filter(fn (?int $d) => $d !== null)
            ->sort()
            ->values();

        if ($days->isEmpty()) {
            return null;
        }

        return (int) $days[intdiv($days->count(), 2)];
    }

    /**
     * Conversions as they happen, newest first.
     *
     * Read from the activity log rather than from the contacts table on purpose.
     * A contact row carries only its current state: undo an import, delete a
     * contact, and the fact that it converted goes with it. The log is
     * append-only, so this feed keeps telling the truth about what a list did
     * even after the list itself has been cleaned up.
     */
    public function conversions(Request $request): JsonResponse
    {
        $events = ActivityLog::where('log_name', 'contacts')
            ->where('event', 'contact_converted')
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        // `properties` is cast to a Collection, so it is read with only() and
        // toArray() — a plain (array) cast on a Collection returns the object's
        // internals, not the data, and every field would come back null.
        $events->through(fn (ActivityLog $log) => [
            'id' => $log->id,
            'contact_id' => $log->subject_id,
            'at' => $log->created_at?->toIso8601String(),
            ...$log->properties
                ->only(['phone', 'name', 'import_label', 'contact_import_id', 'order_id', 'days_to_convert', 'was_customer_before_import', 'via'])
                ->toArray(),
        ]);

        return response()->success($events->toArray());
    }

    /** Every uploaded list, newest first. */
    public function imports(Request $request): JsonResponse
    {
        $imports = ContactImport::with('uploadedBy:id,name')
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return response()->success(
            ContactImportResource::collection($imports)->response()->getData(true),
        );
    }

    /**
     * What a file would do, without doing it.
     *
     * A separate call from the import itself because an operator should see
     * "3,612 new, 388 already customers, 412 unreadable numbers" before
     * committing, not after. The file is uploaded twice — once here, once to
     * commit — which is cheaper and far less fragile than parking a parsed
     * 28,000-row file in server-side session state between two requests.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'name_column' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:200'],
            'phone_column' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:200'],
        ], [
            'file.mimes' => 'That has to be a CSV file. Export the sheet as CSV and try again.',
            'file.max' => 'That file is over 10MB. Split it up.',
        ]);

        return response()->success($this->importer->preview(
            $request->file('file'),
            $validated['name_column'] ?? null,
            $validated['phone_column'] ?? null,
        ));
    }

    /** Commit the file. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'label' => ['required', 'string', 'max:255'],
            'source_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'name_column' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:200'],
            'phone_column' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:200'],
        ], [
            'label.required' => 'Name this list so you can tell it apart from the next one.',
            'file.mimes' => 'That has to be a CSV file. Export the sheet as CSV and try again.',
        ]);

        try {
            $import = $this->importer->import(
                $request->file('file'),
                $validated['label'],
                $validated['source_note'] ?? null,
                $request->user(),
                $validated['name_column'] ?? null,
                $validated['phone_column'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->unprocessable($e->getMessage());
        }

        return response()->created(
            (new ContactImportResource($import->load('uploadedBy')))->resolve(),
        );
    }

    /**
     * Undo an import.
     *
     * Removes only the contacts it created that have not since ordered — see
     * ContactImporter::undo().
     */
    public function undoImport(Request $request, ContactImport $import): JsonResponse
    {
        $removed = $this->importer->undo($import, $request->user());
        $kept = $import->contacts()->converted()->count();

        return response()->success(
            (new ContactImportResource($import->fresh()->load('uploadedBy')))->resolve(),
            $kept === 0
                ? "Removed {$removed} contacts."
                : "Removed {$removed} contacts. Kept {$kept} who have since ordered — they are customers now.",
        );
    }

    /** Drop a single contact. */
    public function destroy(Request $request, Contact $contact): JsonResponse
    {
        activity('admin')
            ->causedBy($request->user())
            ->performedOn($contact)
            ->event('contact_deleted')
            ->withProperties(['phone' => $contact->phone])
            ->log('Contact deleted: '.$contact->phone);

        $contact->delete();

        return response()->deleted();
    }
}
