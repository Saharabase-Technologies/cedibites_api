<?php

use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;

/**
 * Removing an option's photo.
 *
 * Upload could only ever replace, so a photo put on the wrong option stayed
 * there unless you happened to have another one to hand.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->item = MenuItem::factory()->create();
    $this->option = MenuItemOption::factory()->create(['menu_item_id' => $this->item->id]);
});

it('removes the photo from an option', function () {
    $this->actingAs($this->admin)->postJson(
        "/v1/admin/menu-items/{$this->item->id}/options/{$this->option->id}/image",
        ['image' => UploadedFile::fake()->image('jollof.jpg')],
    )->assertSuccessful();

    expect($this->option->fresh()->getFirstMedia('menu-item-options'))->not->toBeNull();

    $this->actingAs($this->admin)
        ->deleteJson("/v1/admin/menu-items/{$this->item->id}/options/{$this->option->id}/image")
        ->assertSuccessful();

    expect($this->option->fresh()->getFirstMedia('menu-item-options'))->toBeNull();
});

it('is safe on an option that has no photo', function () {
    $this->actingAs($this->admin)
        ->deleteJson("/v1/admin/menu-items/{$this->item->id}/options/{$this->option->id}/image")
        ->assertSuccessful();
});

it('refuses an option belonging to a different item', function () {
    $other = MenuItem::factory()->create();

    $this->actingAs($this->admin)
        ->deleteJson("/v1/admin/menu-items/{$other->id}/options/{$this->option->id}/image")
        ->assertNotFound();
});

it('refuses a caller without manage_menu', function () {
    $nobody = User::factory()->create();

    $this->actingAs($nobody)
        ->deleteJson("/v1/admin/menu-items/{$this->item->id}/options/{$this->option->id}/image")
        ->assertForbidden();
});
