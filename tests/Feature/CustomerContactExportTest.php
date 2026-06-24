<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Start from a clean slate so contact counts are deterministic. Order first
    // (FK to customer), then customers (FK to user), then users.
    Order::query()->forceDelete();
    Customer::query()->forceDelete();
    User::query()->forceDelete();

    $role = SpatieRole::findOrCreate('admin', 'api');
    $role->givePermissionTo(SpatiePermission::findOrCreate('view_customers', 'api'));
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

function contactsAdmin(): User
{
    $user = User::factory()->create(['phone' => '+233200000001', 'name' => 'Admin']);
    $user->assignRole('admin');

    return $user;
}

/** Create a registered customer with a specific name + phone on the user. */
function registeredCustomer(string $name, ?string $phone): Customer
{
    $user = User::factory()->create(['name' => $name, 'phone' => $phone]);

    return Customer::factory()->create(['user_id' => $user->id]);
}

/** Hit the export endpoint as an admin and return the assertable response. */
function exportContacts(User $admin)
{
    return test()->actingAs($admin, 'sanctum')
        ->getJson('/v1/admin/customers/export-contacts')
        ->assertSuccessful();
}

// ─── Tests ───────────────────────────────────────────────────────────────────

describe('GET /admin/customers/export-contacts', function () {
    it('rejects unauthenticated requests', function () {
        $this->getJson('/v1/admin/customers/export-contacts')
            ->assertUnauthorized();
    });

    it('exports registered customer contacts as name + phone', function () {
        registeredCustomer('Ama Mensah', '+233241234567');
        $admin = contactsAdmin();

        exportContacts($admin)
            ->assertJsonFragment(['name' => 'Ama Mensah', 'phone' => '+233241234567']);
    });

    it('normalises local 0XXXXXXXXX numbers to +233', function () {
        registeredCustomer('Kofi Local', '0246000111');
        $admin = contactsAdmin();

        exportContacts($admin)
            ->assertJsonFragment(['phone' => '+233246000111']);
    });

    it('sources guest contacts from the order contact phone', function () {
        Order::factory()->create([
            'contact_name' => 'Guest Gibson',
            'contact_phone' => '0277000222',
        ]);
        $admin = contactsAdmin();

        exportContacts($admin)
            ->assertJsonFragment(['name' => 'Guest Gibson', 'phone' => '+233277000222']);
    });

    it('drops malformed, junk and null phone numbers', function () {
        registeredCustomer('Junk Name', 'Grace');          // not a number
        registeredCustomer('Too Short', '+233246');        // too short
        registeredCustomer('Too Long', '+2332464918016');  // 10 digits after +233
        registeredCustomer('Bad Prefix', '+233146000111'); // starts with 1, not 2–9
        registeredCustomer('No Phone', null);              // null
        registeredCustomer('Valid One', '+233249999999');  // the only keeper
        $admin = contactsAdmin();

        $phones = collect(exportContacts($admin)->json('data'))->pluck('phone');

        expect($phones)->toContain('+233249999999')
            ->and($phones)->not->toContain('Grace')
            ->and($phones)->not->toContain('+233246')
            ->and($phones)->not->toContain('+2332464918016')
            ->and($phones)->not->toContain('+233146000111');
    });

    it('de-duplicates a number that appears on both a user and an order', function () {
        registeredCustomer('Dupe Person', '+233248888777');
        Order::factory()->create([
            'contact_name' => 'Dupe Person (guest)',
            'contact_phone' => '0248888777', // same number, local format
        ]);
        $admin = contactsAdmin();

        $occurrences = collect(exportContacts($admin)->json('data'))
            ->where('phone', '+233248888777')
            ->count();

        expect($occurrences)->toBe(1);
    });

    it('only ever returns valid +233 formatted numbers', function () {
        registeredCustomer('Good A', '+233241111111');
        registeredCustomer('Bad B', 'not-a-phone');
        Order::factory()->create(['contact_phone' => '0242222222']);
        $admin = contactsAdmin();

        $phones = collect(exportContacts($admin)->json('data'))->pluck('phone');

        expect($phones)->not->toBeEmpty();
        $phones->each(fn ($p) => expect($p)->toMatch('/^\+233[2-9]\d{8}$/'));
    });
});
