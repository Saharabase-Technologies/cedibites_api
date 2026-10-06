<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\HubtelPaymentService;
use Illuminate\Console\Command;

/**
 * Give a branch its own Hubtel account, so its money stops landing in
 * Ashaiman's.
 *
 * A command rather than a screen because the key is a live payment
 * credential. It is typed with the echo off, encrypted with APP_KEY before it
 * reaches the database, never returned by the API and never written to a log.
 * Passing it as an option would put it in the shell history, so there is no
 * option for it.
 *
 * Before saving, the command asks Hubtel whether it accepts the key from this
 * server. A key Hubtel refuses would fail every MoMo payment at that branch,
 * which is worse than the money going to the company account, so a refused
 * key is not saved unless you insist.
 *
 *   php artisan hubtel:branch-account                      every branch and where it collects
 *   php artisan hubtel:branch-account Lakeside             set it (asks for the key)
 *   php artisan hubtel:branch-account Lakeside --check     ask Hubtel about the stored key
 *   php artisan hubtel:branch-account Lakeside --clear     back to the company account
 */
class HubtelBranchAccount extends Command
{
    protected $signature = 'hubtel:branch-account
                            {branch? : Branch name or id. Leave out to see every branch.}
                            {--account= : The branch\'s Collection Account number, e.g. 2040195}
                            {--api-id= : The API ID (username) of the branch\'s payment key}
                            {--check : Ask Hubtel whether it accepts the stored key from this server. Moves no money.}
                            {--clear : Remove the branch\'s account, so it collects into the company account again}';

    protected $description = 'Set, check or clear the Hubtel account a branch collects into';

    public function handle(HubtelPaymentService $hubtel): int
    {
        if (! $this->argument('branch')) {
            $this->list($hubtel);

            return self::SUCCESS;
        }

        $branch = $this->findBranch((string) $this->argument('branch'));

        if (! $branch) {
            return self::FAILURE;
        }

        if ($this->option('clear')) {
            return $this->clear($branch, $hubtel);
        }

        if ($this->option('check')) {
            return $this->check($branch, $hubtel);
        }

        return $this->set($branch, $hubtel);
    }

    private function list(HubtelPaymentService $hubtel): void
    {
        $rows = Branch::orderBy('id')->get()->map(function (Branch $branch) use ($hubtel) {
            $own = $branch->hubtelAccount();

            return [
                $branch->id,
                $branch->name,
                $own
                    ? "{$own['account_number']} (its own)"
                    : ($hubtel->accountNumber() ?: 'not set').' (company)',
                $own ? $own['api_id'] : '',
            ];
        });

        $this->table(['Id', 'Branch', 'Collects into', 'API ID'], $rows);
    }

    private function set(Branch $branch, HubtelPaymentService $hubtel): int
    {
        $current = $branch->hubtelAccount();

        $account = trim((string) ($this->option('account')
            ?? $this->ask("{$branch->name}'s Collection Account number", $branch->hubtel_account_number)));

        if (! preg_match('/^\d{4,20}$/', $account)) {
            $this->error("\"{$account}\" is not an account number. It is digits only, like 2040195.");

            return self::FAILURE;
        }

        if ($account === $hubtel->accountNumber()) {
            $this->error("{$account} is the company account. A branch that uses it needs no entry; use --clear.");

            return self::FAILURE;
        }

        $taken = Branch::where('hubtel_account_number', $account)->where('id', '!=', $branch->id)->first();

        if ($taken) {
            $this->error("{$account} already belongs to {$taken->name}.");

            return self::FAILURE;
        }

        $apiId = trim((string) ($this->option('api-id')
            ?? $this->ask('API ID (the username)', $branch->hubtel_api_id)));

        if ($apiId === '' || preg_match('/\s/', $apiId)) {
            $this->error('The API ID is one word with no spaces, like jYAkgV4.');

            return self::FAILURE;
        }

        $keepStored = $current && $current['api_id'] === $apiId;
        $prompt = $keepStored
            ? 'API Key (the password). Typing is hidden. Press Enter to keep the stored key'
            : 'API Key (the password). Typing is hidden';

        $key = trim((string) $this->secret($prompt));

        if ($key === '' && $keepStored) {
            $key = $current['api_key'];
        }

        if ($key === '') {
            $this->error('No key was typed. Nothing has changed.');

            return self::FAILURE;
        }

        $this->line('Asking Hubtel whether it accepts this key from this server. No money moves.');

        $result = $this->probeWith($hubtel, $account, $apiId, $key);
        $this->report($result);

        if ($result['verdict'] !== 'accepted'
            && ! $this->confirm("Save it anyway? Until this is fixed, every MoMo payment at {$branch->name} would fail.", false)) {
            $this->line('Nothing saved.');

            return self::FAILURE;
        }

        $branch->forceFill([
            'hubtel_account_number' => $account,
            'hubtel_api_id' => $apiId,
            'hubtel_api_key' => $key,
        ])->save();

        $this->info("{$branch->name} now collects into {$account}.");
        $this->line('Payments already started keep going to the account they were sent to.');

        return self::SUCCESS;
    }

    private function check(Branch $branch, HubtelPaymentService $hubtel): int
    {
        $own = $branch->hubtelAccount();

        if (! $own) {
            $this->line("{$branch->name} has no account of its own. It collects into the company account, {$hubtel->accountNumber()}.");

            return self::SUCCESS;
        }

        $this->line("Asking Hubtel about {$branch->name}'s key for {$own['account_number']}. No money moves.");
        $result = $hubtel->forBranch($branch)->probe();
        $this->report($result);

        return $result['verdict'] === 'accepted' ? self::SUCCESS : self::FAILURE;
    }

    private function clear(Branch $branch, HubtelPaymentService $hubtel): int
    {
        if (! $branch->hubtel_account_number && ! $branch->hubtel_api_id) {
            $this->line("{$branch->name} already collects into the company account.");

            return self::SUCCESS;
        }

        if (! $this->confirm("{$branch->name} will collect into the company account, {$hubtel->accountNumber()}, from the next payment. Go ahead?", false)) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        $branch->forceFill([
            'hubtel_account_number' => null,
            'hubtel_api_id' => null,
            'hubtel_api_key' => null,
        ])->save();

        $this->info("{$branch->name} collects into the company account again.");

        return self::SUCCESS;
    }

    /**
     * The probe runs against values that are not saved yet, so a refused key
     * never touches the branch row.
     */
    private function probeWith(HubtelPaymentService $hubtel, string $account, string $apiId, string $key): array
    {
        $unsaved = new Branch;
        $unsaved->forceFill([
            'hubtel_account_number' => $account,
            'hubtel_api_id' => $apiId,
            'hubtel_api_key' => $key,
        ]);

        return $hubtel->forBranch($unsaved)->probe();
    }

    private function report(array $result): void
    {
        $status = $result['status'] ? "HTTP {$result['status']}" : 'no answer';

        match ($result['verdict']) {
            'accepted' => $this->info("Hubtel accepted the key from this server ({$status}). The first MoMo payment is what proves it can take money."),
            'key_refused' => $this->error("Hubtel refused the API ID or key ({$status}). Check both against the Programmable Keys page."),
            'ip_refused' => $this->error("Hubtel refused this server's IP address ({$status}). Ask the Retail Systems Engineer to whitelist it for this key."),
            default => $this->error("Could not get an answer from Hubtel ({$status})."),
        };

        if ($result['detail']) {
            $this->line("Hubtel said: {$result['detail']}");
        }
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
