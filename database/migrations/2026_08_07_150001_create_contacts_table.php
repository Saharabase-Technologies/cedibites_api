<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People we hold a number for but have never sold anything to.
 *
 * DELIBERATELY NOT the `customers` table, and that separation is the entire
 * point of this feature rather than an implementation detail. Every customer
 * figure in the business — total customers, new customers, top customers, the
 * dashboard, the board pack — is a query over `customers` and `orders`. Keeping
 * imported numbers in their own table means none of those figures can move when
 * somebody uploads a list, without a single one of them being edited to exclude
 * them. A `customers.is_imported` flag would have had the opposite property:
 * correct only in the places that remembered to filter on it, and quietly wrong
 * everywhere that did not.
 *
 * A contact is a marketing asset. A customer is somebody who has bought food.
 * The bridge between the two is `converted_at`, stamped when an order first
 * arrives on this number — see ContactConverter. After that the person is a real
 * customer, counted by the real customer metrics, through the order they placed;
 * this row survives only to record where we originally found them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            $table->string('name')->nullable();

            /*
             * Normalised to +233XXXXXXXXX before it ever reaches this column,
             * and unique. The number IS the identity here — there is no account,
             * no email, nothing else to key on — so the same person appearing in
             * three uploaded lists has to collapse to one row or every campaign
             * texts them three times and bills us three times.
             */
            $table->string('phone', 20)->unique();

            // 'import' today. A column rather than a boolean because the next
            // sources are already obvious (walk-in sign-up sheet, referral,
            // event) and they want telling apart in reporting.
            $table->string('source', 32)->default('import');

            $table->foreignId('contact_import_id')->nullable()->constrained()->nullOnDelete();

            /*
             * The moment this stopped being a supplementary contact and became
             * somebody who has bought from us.
             *
             * Set to the order's own timestamp, not to now(): a number that
             * already had orders when it was imported converted years ago, and
             * writing the import date here would make every acquisition report
             * read as though the list caused the sale.
             */
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_order_id')->nullable()->constrained('orders')->nullOnDelete();

            // Set only if the person also holds a customer record. Plenty of
            // converted contacts never will — a guest checkout at the counter
            // buys food without creating one.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Whether they had already ordered from us before this list was
             * uploaded. Stored rather than derived from
             * `converted_at < created_at`, which would be a clock comparison
             * standing in for a fact, and would flip meaning the first time an
             * import was backdated or a row was touched.
             *
             * This is what keeps an import honest: 4,000 numbers of which 3,900
             * are existing customers is 100 new contacts, and the difference
             * should be visible on the day of the upload.
             */
            $table->boolean('was_customer_before_import')->default(false);

            // Whatever else was in the CSV. Kept verbatim so a column nobody
            // mapped is not silently destroyed by the import.
            $table->json('metadata')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The list view filters on converted/not-converted constantly, and
            // the audience resolver reads every unconverted row on every count.
            $table->index('converted_at');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
