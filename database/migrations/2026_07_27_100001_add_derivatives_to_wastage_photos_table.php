<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smaller renditions of evidence photos.
 *
 * The claim detail page was downloading the full-resolution originals to draw a
 * grid of 112px squares. Measured on production after the first field test: one
 * claim held six photos totalling ~14 MB, and every visit to that page pulled
 * all of it. A 6.2 MB phone photo to render a thumbnail the size of a stamp.
 *
 *   thumb   ~400px  - the grid
 *   display ~1600px - the lightbox
 *   original         - untouched, and still the record
 *
 * The original is never replaced. This is dispute evidence: whatever the phone
 * sent is what gets kept, and the derivatives are a convenience layered on top.
 * Both columns are nullable and every consumer falls back to the original, so
 * video rows, rows predating this, and any image GD chokes on all keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_wastage_photos', function (Blueprint $table) {
            $table->string('thumb_path')->nullable()->after('url');
            $table->string('thumb_url')->nullable()->after('thumb_path');
            $table->string('display_path')->nullable()->after('thumb_url');
            $table->string('display_url')->nullable()->after('display_path');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_wastage_photos', function (Blueprint $table) {
            $table->dropColumn(['thumb_path', 'thumb_url', 'display_path', 'display_url']);
        });
    }
};
