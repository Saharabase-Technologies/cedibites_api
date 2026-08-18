<?php

use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Contacts\ContactImporter;
use App\Services\Contacts\PhoneNormaliser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    Order::query()->forceDelete();
    Customer::query()->forceDelete();
    Contact::query()->forceDelete();
    User::query()->forceDelete();

    SpatieRole::findOrCreate('admin', 'api')
        ->givePermissionTo(SpatiePermission::findOrCreate('manage_campaigns', 'api'));
    SpatieRole::findOrCreate('cashier', 'api')
        ->givePermissionTo(SpatiePermission::findOrCreate('view_customers', 'api'));
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

function contactAdmin(): User
{
    $user = User::factory()->create(['phone' => '+233200000009']);
    $user->assignRole('admin');

    return $user;
}

function csv(string $contents, string $name = 'list.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

function importer(): ContactImporter
{
    return app(ContactImporter::class);
}

// ─── Phone normalisation ─────────────────────────────────────────────────────

it('reads the shapes a Ghana number arrives in', function (string $raw, ?string $expected) {
    expect(PhoneNormaliser::normalise($raw))->toBe($expected);
})->with([
    'already normal' => ['+233241234567', '+233241234567'],
    'local' => ['0241234567', '+233241234567'],
    'international, no plus' => ['233241234567', '+233241234567'],
    'dialled international' => ['00233241234567', '+233241234567'],
    'spaced' => ['024 123 4567', '+233241234567'],
    'punctuated' => ['+233 (24) 123-4567', '+233241234567'],
    'excel apostrophe' => ["'0241234567", '+233241234567'],

    // The one that matters most: Excel eats the leading zero on any column it
    // decides is numeric, and a list mangled this way is the common case.
    'leading zero eaten by excel' => ['241234567', '+233241234567'],

    'scientific notation is unrecoverable' => ['2.33241E+11', null],
    'too short' => ['02412345', null],
    'too long' => ['+2332412345678', null],
    'landline prefix is not mobile' => ['+233112345678', null],
    'not a number at all' => ['Kwame Mensah', null],
    'empty' => ['', null],
]);

// ─── Parsing ─────────────────────────────────────────────────────────────────

it('finds the name and phone columns from the headers', function () {
    $preview = importer()->preview(csv(
        "Full Name,Mobile Number,City\nKwame,0241234567,Accra\nAma,0551234567,Kumasi\n"
    ));

    expect($preview['has_header'])->toBeTrue()
        ->and($preview['name_column'])->toBe(0)
        ->and($preview['phone_column'])->toBe(1)
        ->and($preview['counts']['new'])->toBe(2);
});

it('finds the columns by content when the headers say nothing useful', function () {
    $preview = importer()->preview(csv(
        "Col A,Col B\nKwame Mensah,0241234567\nAma Serwaa,0551234567\n"
    ));

    expect($preview['phone_column'])->toBe(1)
        ->and($preview['name_column'])->toBe(0)
        ->and($preview['counts']['new'])->toBe(2);
});

it('reads a file with no header row', function () {
    $preview = importer()->preview(csv("Kwame,0241234567\nAma,0551234567\n"));

    expect($preview['has_header'])->toBeFalse()
        ->and($preview['counts']['new'])->toBe(2);
});

it('reads a semicolon-separated file, which is what Excel writes in half the world', function () {
    $preview = importer()->preview(csv("Name;Phone\nKwame;0241234567\nAma;0551234567\n"));

    expect($preview['phone_column'])->toBe(1)
        ->and($preview['counts']['new'])->toBe(2);
});

it('strips the BOM Excel puts on the first header', function () {
    $preview = importer()->preview(csv("\u{FEFF}Name,Phone\nKwame,0241234567\n"));

    expect($preview['headers'][0])->toBe('Name')
        ->and($preview['counts']['new'])->toBe(1);
});

it('says so plainly when no column holds a phone number', function () {
    $preview = importer()->preview(csv("Name,City\nKwame,Accra\nAma,Kumasi\n"));

    expect($preview['phone_column'])->toBeNull()
        ->and($preview['error'])->toContain('phone');
});

it('reports bad rows with the value that was rejected', function () {
    $preview = importer()->preview(csv("Name,Phone\nKwame,0241234567\nAma,not-a-number\n"));

    expect($preview['counts']['invalid'])->toBe(1)
        ->and($preview['invalid_sample'][0]['value'])->toBe('not-a-number');
});

it('counts a number repeated in the file once', function () {
    $preview = importer()->preview(csv(
        "Name,Phone\nKwame,0241234567\nKwame again,+233241234567\nAma,0551234567\n"
    ));

    expect($preview['counts']['new'])->toBe(2)
        ->and($preview['counts']['duplicate_in_file'])->toBe(1);
});

it('previews without writing anything', function () {
    importer()->preview(csv("Name,Phone\nKwame,0241234567\n"));

    expect(Contact::count())->toBe(0)
        ->and(ContactImport::count())->toBe(0);
});

// ─── Importing ───────────────────────────────────────────────────────────────

it('imports contacts and records the batch', function () {
    $user = contactAdmin();

    $import = importer()->import(
        csv("Name,Phone\nKwame,0241234567\nAma,0551234567\n"),
        'Accra Mall activation',
        'Collected at the stand',
        $user,
    );

    expect($import->imported_count)->toBe(2)
        ->and($import->label)->toBe('Accra Mall activation')
        ->and(Contact::count())->toBe(2);

    $contact = Contact::where('phone', '+233241234567')->first();

    expect($contact->name)->toBe('Kwame')
        ->and($contact->source)->toBe('import')
        ->and($contact->contact_import_id)->toBe($import->id)
        ->and($contact->converted_at)->toBeNull()
        ->and($contact->status())->toBe('supplementary');
});

it('normalises numbers on the way in, so the same person cannot be stored twice', function () {
    $user = contactAdmin();

    importer()->import(csv("Name,Phone\nKwame,0241234567\n"), 'First', null, $user);

    // The same number, written the way another export would write it.
    expect(fn () => importer()->import(csv("Name,Phone\nKwame,+233241234567\n"), 'Second', null, $user))
        ->toThrow(RuntimeException::class);

    expect(Contact::count())->toBe(1);
});

it('keeps the unmapped columns rather than throwing them away', function () {
    importer()->import(
        csv("Name,Phone,City\nKwame,0241234567,Accra\n"),
        'With extras',
        null,
        contactAdmin(),
    );

    expect(array_values(Contact::first()->metadata))->toContain('Accra');
});

it('refuses a file whose numbers are all already in the contact base', function () {
    $user = contactAdmin();
    importer()->import(csv("Name,Phone\nKwame,0241234567\n"), 'First', null, $user);

    expect(fn () => importer()->import(csv("Name,Phone\nKwame,0241234567\n"), 'Again', null, $user))
        ->toThrow(RuntimeException::class, 'already in the contact base');
});

it('blames the right thing when every number in the file is unreadable', function () {
    // A phone column the parser can find, holding nothing it can use. The
    // remedy — re-export the column as text — is the whole content of the
    // message; "nothing to import" alone sends the operator back with no idea
    // what to change.
    $message = null;

    try {
        importer()->import(
            csv("Name,Phone\nKwame,024123\nAma,055123\n"),
            'Broken',
            null,
            contactAdmin(),
            phoneColumn: 1,
        );
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('as text');
});

it('lets a corrected file be re-imported after an undo', function () {
    // The operator maps the wrong column, undoes, and uploads the same file
    // again. A soft delete would leave the unique index holding the mistake and
    // refuse the correction as a duplicate.
    $user = contactAdmin();

    $import = importer()->import(csv("Name,Phone\nKwame,0241234567\n"), 'First go', null, $user);
    importer()->undo($import, $user);

    $second = importer()->import(csv("Name,Phone\nKwame,0241234567\n"), 'Second go', null, $user);

    expect($second->imported_count)->toBe(1)
        ->and(Contact::count())->toBe(1);
});

// ─── The separation that is the point of the feature ─────────────────────────

it('creates no customer record, so no customer figure can move', function () {
    $before = Customer::count();

    importer()->import(csv("Name,Phone\nKwame,0241234567\nAma,0551234567\n"), 'List', null, contactAdmin());

    expect(Customer::count())->toBe($before)
        ->and(Contact::count())->toBe(2);
});

it('leaves the customer analytics untouched', function () {
    $metrics = fn () => app(\App\Services\Analytics\AnalyticsService::class)->getCustomerMetrics();

    $before = $metrics()['total_customers'];

    importer()->import(csv("Name,Phone\nKwame,0241234567\nAma,0551234567\n"), 'List', null, contactAdmin());

    expect($metrics()['total_customers'])->toBe($before);
});

it('marks a number that already ordered as an existing customer, not an acquisition', function () {
    $user = contactAdmin();

    $order = Order::factory()->create([
        'contact_phone' => '+233241234567',
        'status' => 'completed',
        'created_at' => now()->subYear(),
    ]);

    $import = importer()->import(
        csv("Name,Phone\nKwame,0241234567\nAma,0551234567\n"),
        'Bought list',
        null,
        $user,
    );

    expect($import->already_customer_count)->toBe(1)
        ->and($import->imported_count)->toBe(2);

    $existing = Contact::where('phone', '+233241234567')->first();

    expect($existing->was_customer_before_import)->toBeTrue()
        ->and($existing->status())->toBe('already_customer')
        ->and($existing->isAcquired())->toBeFalse()
        ->and($existing->converted_order_id)->toBe($order->id)
        // Stamped with the order's date, not today's — otherwise a list of
        // long-standing customers reads as having acquired them all at once.
        ->and($existing->converted_at->isSameDay(now()->subYear()))->toBeTrue();

    expect(Contact::where('phone', '+233551234567')->first()->status())->toBe('supplementary');
});

it('matches an existing customer whose order stored the number in a different shape', function () {
    Order::factory()->create([
        // Typed at the counter, local format — the CSV has it international.
        'contact_phone' => '0241234567',
        'status' => 'completed',
    ]);

    $import = importer()->import(
        csv("Name,Phone\nKwame,+233241234567\n"),
        'List',
        null,
        contactAdmin(),
    );

    expect($import->already_customer_count)->toBe(1);
});

it('ignores a cancelled order when deciding who is already a customer', function () {
    Order::factory()->create([
        'contact_phone' => '+233241234567',
        'status' => 'cancelled',
    ]);

    $import = importer()->import(csv("Name,Phone\nKwame,0241234567\n"), 'List', null, contactAdmin());

    expect($import->already_customer_count)->toBe(0)
        ->and(Contact::first()->status())->toBe('supplementary');
});

// ─── Undo ────────────────────────────────────────────────────────────────────

it('undoes an import but keeps the contacts who have since ordered', function () {
    $user = contactAdmin();

    $import = importer()->import(
        csv("Name,Phone\nKwame,0241234567\nAma,0551234567\n"),
        'List',
        null,
        $user,
    );

    Contact::where('phone', '+233241234567')->update(['converted_at' => now()]);

    $removed = importer()->undo($import, $user);

    expect($removed)->toBe(1)
        ->and(Contact::count())->toBe(1)
        ->and(Contact::first()->phone)->toBe('+233241234567')
        // The batch survives the undo, so its counts are still readable.
        ->and(ContactImport::find($import->id))->not->toBeNull();
});

// ─── Endpoints ───────────────────────────────────────────────────────────────

it('lets an admin preview and import over the API', function () {
    $user = contactAdmin();

    $this->actingAs($user, 'sanctum')
        ->postJson('/v1/admin/contacts/import/preview', [
            'file' => csv("Name,Phone\nKwame,0241234567\n"),
        ])
        ->assertOk()
        ->assertJsonPath('data.counts.new', 1);

    $this->actingAs($user, 'sanctum')
        ->postJson('/v1/admin/contacts/import', [
            'file' => csv("Name,Phone\nKwame,0241234567\n"),
            'label' => 'Test list',
        ])
        ->assertCreated();

    expect(Contact::count())->toBe(1);
});

it('keeps the contact base away from everybody who only holds view_customers', function () {
    $cashier = User::factory()->create(['phone' => '+233200000010']);
    $cashier->assignRole('cashier');

    $this->actingAs($cashier, 'sanctum')->getJson('/v1/admin/contacts')->assertForbidden();
    $this->actingAs($cashier, 'sanctum')->getJson('/v1/admin/contacts/stats')->assertForbidden();
    $this->actingAs($cashier, 'sanctum')
        ->postJson('/v1/admin/contacts/import', ['label' => 'x'])
        ->assertForbidden();
});

it('reports the counts that keep contacts apart from customers', function () {
    Contact::factory()->count(3)->create();
    Contact::factory()->acquired()->create();
    Contact::factory()->alreadyCustomer()->create();

    $this->actingAs(contactAdmin(), 'sanctum')
        ->getJson('/v1/admin/contacts/stats')
        ->assertOk()
        ->assertJsonPath('data.total', 5)
        ->assertJsonPath('data.supplementary', 3)
        ->assertJsonPath('data.acquired', 1)
        ->assertJsonPath('data.already_customer', 1);
});

it('filters the list by status', function () {
    Contact::factory()->count(2)->create();
    Contact::factory()->acquired()->create();

    $this->actingAs(contactAdmin(), 'sanctum')
        ->getJson('/v1/admin/contacts?status=supplementary')
        ->assertOk()
        ->assertJsonCount(2, 'data.data');
});

it('reports what has happened lately, not only the running totals', function () {
    // Totals go up forever and look like progress whatever happens. These are
    // the figures that say whether anything is working this month.
    Contact::factory()->count(3)->create();

    Contact::factory()->create(['created_at' => now()->subDays(14)])
        ->update(['converted_at' => now()->subDays(4)]);
    Contact::factory()->create(['created_at' => now()->subDays(60)])
        ->update(['converted_at' => now()->subDays(45)]);

    $this->actingAs(contactAdmin(), 'sanctum')
        ->getJson('/v1/admin/contacts/stats')
        ->assertOk()
        ->assertJsonPath('data.acquired', 2)
        ->assertJsonPath('data.acquired_last_7_days', 1)
        ->assertJsonPath('data.acquired_last_30_days', 1)
        // 10 and 15 days; the upper of the two is the median of an even set here.
        ->assertJsonPath('data.median_days_to_convert', 15);
});

it('serves the conversion feed, newest first', function () {
    Contact::factory()->create(['phone' => '+233241111111']);
    Contact::factory()->create(['phone' => '+233242222222']);

    Order::factory()->create(['customer_id' => null, 'contact_phone' => '+233241111111', 'status' => 'completed']);
    Order::factory()->create(['customer_id' => null, 'contact_phone' => '+233242222222', 'status' => 'completed']);

    $this->actingAs(contactAdmin(), 'sanctum')
        ->getJson('/v1/admin/contacts/conversions')
        ->assertOk()
        ->assertJsonCount(2, 'data.data')
        ->assertJsonPath('data.data.0.phone', '+233242222222');
});

it('keeps the conversion feed behind manage_campaigns', function () {
    $cashier = User::factory()->create(['phone' => '+233200000011']);
    $cashier->assignRole('cashier');

    $this->actingAs($cashier, 'sanctum')
        ->getJson('/v1/admin/contacts/conversions')
        ->assertForbidden();
});
