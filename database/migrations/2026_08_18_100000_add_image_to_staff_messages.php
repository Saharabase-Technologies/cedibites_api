<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An image on a staff message — a photo of the till, a screenshot of the fault.
 *
 * Stores the RELATIVE path, not a full URL. The URL is built at read time from
 * the configured disk, so moving storage or putting a CDN in front does not
 * strand every message ever sent pointing at the old host. It is also what makes
 * the upload endpoint safe to trust: the path is one we issued, never a string
 * the client chose, so a caller cannot point a message at an arbitrary URL and
 * have it render inside our chrome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_messages', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('staff_messages', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
