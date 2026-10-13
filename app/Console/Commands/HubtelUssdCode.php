<?php

namespace App\Console\Commands;

use App\Models\Branch;
use Illuminate\Console\Command;

/**
 * Say which USSD code belongs to which branch.
 *
 * A customer dials *713*1552*50# and our service has to know 1552 is
 * Lakeside. The codes differ between beta and production and may change if
 * Hubtel issues new ones, so they are set here and not seeded.
 *
 *   php artisan hubtel:ussd-code                     every branch and its code
 *   php artisan hubtel:ussd-code Lakeside 1552       Lakeside is *713*1552#
 *   php artisan hubtel:ussd-code Lakeside --clear    Lakeside can no longer be dialled
 */
class HubtelUssdCode extends Command
{
    protected $signature = 'hubtel:ussd-code
                            {branch? : Branch name or id. Leave out to see every branch.}
                            {code? : The branch\'s part of the code, e.g. 1552 for *713*1552#}
                            {--clear : Remove the branch\'s code}';

    protected $description = 'Set, show or clear the USSD code customers dial to pay a branch';

    public function handle(): int
    {
        if (! $this->argument('branch')) {
            $this->table(
                ['Id', 'Branch', 'Code'],
                Branch::orderBy('id')->get()->map(fn (Branch $branch) => [$branch->id, $branch->name, $branch->hubtel_ussd_code ?? '']),
            );

            return self::SUCCESS;
        }

        $branch = $this->findBranch((string) $this->argument('branch'));

        if (! $branch) {
            return self::FAILURE;
        }

        if ($this->option('clear')) {
            $branch->forceFill(['hubtel_ussd_code' => null])->save();
            $this->info("{$branch->name} has no code now. A dial for it will be asked which branch.");

            return self::SUCCESS;
        }

        $code = trim((string) $this->argument('code'));

        if (! preg_match('/^\d{2,12}$/', $code)) {
            $this->error('Give the branch\'s part of the code as digits only, like 1552 for *713*1552#.');

            return self::FAILURE;
        }

        $taken = Branch::where('hubtel_ussd_code', $code)->where('id', '!=', $branch->id)->first();

        if ($taken) {
            $this->error("{$code} already belongs to {$taken->name}.");

            return self::FAILURE;
        }

        $branch->forceFill(['hubtel_ussd_code' => $code])->save();
        $this->info("A dial that carries {$code} now pays {$branch->name}.");

        return self::SUCCESS;
    }

    private function findBranch(string $needle): ?Branch
    {
        $branch = ctype_digit($needle)
            ? Branch::find((int) $needle)
            : Branch::whereRaw('LOWER(name) = ?', [mb_strtolower($needle)])->first();

        if (! $branch) {
            $names = Branch::orderBy('name')->pluck('name')->implode(', ');
            $this->error("No branch called \"{$needle}\". The branches are: {$names}.");
        }

        return $branch;
    }
}
