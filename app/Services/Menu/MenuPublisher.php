<?php

namespace App\Services\Menu;

use App\Enums\MenuPublicationStatus;
use App\Events\MenuUpdatedEvent;
use App\Models\CartItem;
use App\Models\MenuDraft;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\MenuPublication;
use App\Models\MenuPublicationChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Puts the waiting menu changes live, and puts them back.
 *
 * Publish takes whatever is waiting at that moment, in one transaction. A
 * scheduled publish therefore carries changes typed in after it was set.
 *
 * Checkout and the till already charge the option's price at the moment of the
 * order, so the server is right the instant this commits. What it adds is the
 * rest: baskets repriced, and a signal so open tills and menus reload.
 */
class MenuPublisher
{
    public function publishNow(User $by): MenuPublication
    {
        return $this->publish(null, $by);
    }

    /**
     * Run a scheduled publish whose time has come. A publish that was called
     * off, or already went live from "Go live now", is returned untouched.
     */
    public function publishScheduled(MenuPublication $due): MenuPublication
    {
        return $this->publish($due, null);
    }

    public function undo(MenuPublication $publication, User $by): MenuPublication
    {
        $publication = DB::transaction(function () use ($publication, $by) {
            $publication = MenuPublication::query()->lockForUpdate()->findOrFail($publication->id);

            if ($publication->status !== MenuPublicationStatus::Published) {
                throw ValidationException::withMessages([
                    'publication' => 'Only changes that are live can be undone.',
                ]);
            }

            $later = MenuPublication::query()
                ->where('status', MenuPublicationStatus::Published->value)
                ->where('id', '>', $publication->id)
                ->exists();

            if ($later) {
                throw ValidationException::withMessages([
                    'publication' => 'Only the latest publish can be undone. Undo the later one first.',
                ]);
            }

            $skipped = 0;
            $repriced = [];
            $removed = [];

            foreach ($publication->changes()->orderByDesc('id')->get() as $change) {
                if (! $this->revert($change, $repriced, $removed)) {
                    $skipped++;
                }
            }

            $this->repriceCarts($repriced);

            // A basket line for an option that no longer exists would check
            // out at the dish's first option instead.
            if ($removed !== []) {
                $this->activeCartLines()->whereIn('menu_item_option_id', $removed)->delete();
            }

            $publication->update([
                'status' => MenuPublicationStatus::Undone,
                'undone_at' => now(),
                'undone_by' => $by->id,
                'note' => match (true) {
                    $skipped === 0 => null,
                    $skipped === 1 => '1 value was changed again since. It was left as it is.',
                    default => "{$skipped} values were changed again since. They were left as they are.",
                },
            ]);

            return $publication;
        });

        $this->announce($publication, 'undone');

        return $publication;
    }

    private function publish(?MenuPublication $due, ?User $by): MenuPublication
    {
        [$publication, $wentLive] = DB::transaction(function () use ($due, $by) {
            $scheduled = MenuPublication::query()
                ->where('status', MenuPublicationStatus::Scheduled->value)
                ->when($due, fn (Builder $q) => $q->whereKey($due->id))
                ->lockForUpdate()
                ->first();

            if ($due !== null && $scheduled === null) {
                return [$due->fresh(), false];
            }

            $drafts = MenuDraft::query()->with(['menuItem', 'option'])->lockForUpdate()->orderBy('id')->get();

            if ($drafts->isEmpty()) {
                if ($scheduled !== null && $by === null) {
                    $scheduled->update([
                        'status' => MenuPublicationStatus::Cancelled,
                        'note' => 'Nothing was waiting when the time came.',
                    ]);

                    return [$scheduled, false];
                }

                throw ValidationException::withMessages(['drafts' => 'Nothing is waiting to go live.']);
            }

            // "Go live now" on a scheduled publish is that same publish, early.
            $publication = $scheduled ?? MenuPublication::create([
                'status' => MenuPublicationStatus::Published,
            ]);

            $count = $this->apply($publication, $drafts);

            $publication->update([
                'status' => MenuPublicationStatus::Published,
                'published_at' => now(),
                'published_by' => $by?->id,
                'change_count' => $count,
            ]);

            return [$publication, true];
        });

        if ($wentLive) {
            $this->announce($publication, 'published');
        }

        return $publication;
    }

    /**
     * @param  Collection<int, MenuDraft>  $drafts
     */
    private function apply(MenuPublication $publication, Collection $drafts): int
    {
        // Names as they stood before anything here changed them, so the log
        // reads the way the menu read.
        $names = $this->namesBefore($drafts);

        $count = 0;
        $repriced = [];

        foreach ($drafts as $draft) {
            $changed = match (true) {
                $draft->field === MenuDraft::NEW_OPTION => $this->createOption($publication, $draft),
                in_array($draft->field, MenuDraft::OPTION_FIELDS, true) => $this->changeOption($publication, $draft, $names, $repriced),
                default => $this->changeDish($publication, $draft, $names),
            };

            $count += $changed ? 1 : 0;
        }

        MenuDraft::query()->whereKey($drafts->modelKeys())->delete();

        $this->repriceCarts($repriced);

        return $count;
    }

    /**
     * @param  array<string, string>  $names
     * @param  array<int, float>  $repriced
     */
    private function changeOption(MenuPublication $publication, MenuDraft $draft, array $names, array &$repriced): bool
    {
        $option = $draft->option;

        if ($option === null || $option->trashed()) {
            return false;
        }

        $old = MenuValues::current($option, $draft->field);
        $new = MenuValues::normalise($draft->field, $draft->value);

        if ($old === $new) {
            return false;
        }

        $option->forceFill([$draft->field => $new])->save();

        if ($draft->field === 'price') {
            $repriced[$option->id] = $new;
        }

        $this->log($publication, $option->menu_item_id, $option->id, $draft->field, $old, $new, $names['o'.$option->id]);

        return true;
    }

    /**
     * @param  array<string, string>  $names
     */
    private function changeDish(MenuPublication $publication, MenuDraft $draft, array $names): bool
    {
        $item = $draft->menuItem;

        if ($item === null || $item->trashed()) {
            return false;
        }

        if ($draft->field === 'off_sale') {
            if (! $item->is_available) {
                return false;
            }

            $item->forceFill(['is_available' => false])->save();
            $this->log($publication, $item->id, null, 'is_available', true, false, $names['i'.$item->id]);

            return true;
        }

        $old = MenuValues::current($item, $draft->field);
        $new = MenuValues::normalise($draft->field, $draft->value);

        if ($old === $new) {
            return false;
        }

        $item->forceFill([$draft->field => $new])->save();
        $this->log($publication, $item->id, null, $draft->field, $old, $new, $names['i'.$item->id]);

        return true;
    }

    private function createOption(MenuPublication $publication, MenuDraft $draft): bool
    {
        $item = $draft->menuItem;

        if ($item === null || $item->trashed()) {
            return false;
        }

        $spec = (array) $draft->value;

        $option = $item->options()->create([
            'option_key' => MenuValues::freeOptionKey($item, (string) ($spec['option_key'] ?? $spec['option_label'] ?? 'option')),
            'option_label' => trim((string) $spec['option_label']),
            'display_name' => trim((string) $spec['display_name']),
            'price' => MenuValues::normalise('price', $spec['price']),
            'display_order' => (int) ($spec['display_order'] ?? ((int) $item->options()->max('display_order') + 1)),
            'is_available' => true,
        ]);

        $this->log($publication, $item->id, $option->id, 'created', null, [
            'option_label' => $option->option_label,
            'display_name' => $option->display_name,
            'price' => (float) $option->price,
        ], $option->display_name);

        return true;
    }

    /**
     * @param  array<int, float>  $repriced
     * @param  list<int>  $removed
     */
    private function revert(MenuPublicationChange $change, array &$repriced, array &$removed): bool
    {
        if ($change->field === 'created') {
            $option = MenuItemOption::query()->find($change->menu_item_option_id);

            if ($option !== null) {
                $option->delete();
                $removed[] = $option->id;
            }

            return true;
        }

        if ($change->field === 'is_available') {
            $item = MenuItem::query()->find($change->menu_item_id);

            if ($item === null || (bool) $item->is_available !== (bool) $change->new_value) {
                return false;
            }

            $item->forceFill(['is_available' => (bool) $change->old_value])->save();

            return true;
        }

        $target = $change->menu_item_option_id !== null
            ? MenuItemOption::query()->find($change->menu_item_option_id)
            : MenuItem::query()->find($change->menu_item_id);

        if ($target === null) {
            return false;
        }

        // Somebody changed it again since. Theirs stands.
        if (MenuValues::current($target, $change->field) !== MenuValues::normalise($change->field, $change->new_value)) {
            return false;
        }

        // The old text exactly as it was, double spaces and all. Only a price
        // is normalised, to the pesewa.
        $old = $change->field === 'price'
            ? MenuValues::normalise('price', $change->old_value)
            : $change->old_value;
        $target->forceFill([$change->field => $old])->save();

        if ($change->field === 'price' && $target instanceof MenuItemOption) {
            $repriced[$target->id] = $old;
        }

        return true;
    }

    /**
     * A basket filled before the switch shows the price it will be charged.
     * Checkout reprices anyway. This is so the cart sheet agrees with it.
     *
     * @param  array<int, float>  $prices  option id => price
     */
    private function repriceCarts(array $prices): void
    {
        foreach ($prices as $optionId => $price) {
            $amount = number_format((float) $price, 2, '.', '');

            $this->activeCartLines()
                ->where('menu_item_option_id', $optionId)
                ->update([
                    'unit_price' => $amount,
                    'subtotal' => DB::raw('quantity * '.$amount),
                ]);
        }
    }

    /**
     * @return Builder<CartItem>
     */
    private function activeCartLines(): Builder
    {
        return CartItem::query()->whereHas('cart', fn (Builder $q) => $q->where('status', 'active'));
    }

    /**
     * @param  Collection<int, MenuDraft>  $drafts
     * @return array<string, string>
     */
    private function namesBefore(Collection $drafts): array
    {
        $names = [];

        foreach ($drafts as $draft) {
            $item = $draft->menuItem;

            if ($item !== null) {
                $names['i'.$item->id] = $item->name;
            }

            if ($draft->option !== null && $item !== null) {
                $names['o'.$draft->option->id] = MenuValues::lineName($item, $draft->option);
            }
        }

        return $names;
    }

    private function log(
        MenuPublication $publication,
        int $itemId,
        ?int $optionId,
        string $field,
        mixed $old,
        mixed $new,
        string $lineName,
    ): void {
        MenuPublicationChange::create([
            'menu_publication_id' => $publication->id,
            'menu_item_id' => $itemId,
            'menu_item_option_id' => $optionId,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'line_name' => $lineName,
        ]);
    }

    /**
     * Tell every open till and menu. A Reverb outage must never undo a
     * publish that already committed: the prices are right on the server,
     * and screens catch up on their next load.
     */
    private function announce(MenuPublication $publication, string $kind): void
    {
        try {
            event(new MenuUpdatedEvent($publication->id, $kind));
        } catch (\Throwable $e) {
            Log::warning('Menu change went live but the signal did not go out', [
                'publication_id' => $publication->id,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
