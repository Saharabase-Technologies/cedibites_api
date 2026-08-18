<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\Contacts\ContactConverter;
use Illuminate\Console\Command;

/**
 * Promote any imported contact whose number has since bought something.
 *
 * The order observer does this in real time; this is the net under it. Orders
 * written by a seeder, a backfill or a direct database edit never fire an
 * observer, and a contact left unconverted keeps being counted as supplementary
 * and keeps being texted as a prospect long after they became a regular.
 *
 * Idempotent — an already-converted contact is never re-stamped, so this is safe
 * to run on a schedule or by hand as often as you like.
 */
class ReconcileContacts extends Command
{
    protected $signature = 'contacts:reconcile {--dry-run : Report what would change without writing}';

    protected $description = 'Mark imported contacts as converted where their number has since ordered';

    public function handle(ContactConverter $converter): int
    {
        $pending = Contact::unconverted()->count();

        $this->info("{$pending} contact(s) currently supplementary.");

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing written.');

            return self::SUCCESS;
        }

        $converted = $converter->reconcile();

        $this->info($converted === 0
            ? 'Nothing to promote — every supplementary contact is still supplementary.'
            : "Promoted {$converted} contact(s) to acquired customers.");

        return self::SUCCESS;
    }
}
