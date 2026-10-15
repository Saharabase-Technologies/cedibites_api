<?php

namespace App\Http\Controllers\Api;

use App\Enums\MenuPublicationStatus;
use App\Http\Controllers\Controller;
use App\Models\MenuDraft;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\MenuPublication;
use App\Services\Menu\MenuDrafts;
use App\Services\Menu\MenuPriceBoard;
use App\Services\Menu\MenuPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The New prices page: changes typed in ahead of time, and the button that
 * puts them live, now or at a set time.
 *
 * Every write answers with the whole board, so the page has one source and
 * never has to stitch a response into what it already holds.
 *
 * Routes sit behind `manage_menu`. The RoleSeeder: "Prices are Admin's."
 */
class MenuPriceController extends Controller
{
    public function __construct(
        private MenuPriceBoard $priceBoard,
        private MenuDrafts $drafts,
        private MenuPublisher $publisher,
    ) {}

    public function index(): JsonResponse
    {
        return $this->board();
    }

    /** Set one waiting change. An empty value clears it. */
    public function setDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'menu_item_option_id' => ['nullable', 'integer', 'exists:menu_item_options,id'],
            'field' => ['required', 'string', Rule::in([...MenuDraft::OPTION_FIELDS, ...MenuDraft::DISH_FIELDS])],
            'value' => ['present', 'nullable'],
        ]);

        $item = MenuItem::query()->findOrFail($validated['menu_item_id']);
        $option = isset($validated['menu_item_option_id'])
            ? MenuItemOption::query()->findOrFail($validated['menu_item_option_id'])
            : null;
        $field = $validated['field'];
        $value = $validated['value'];

        if ($option !== null && $option->menu_item_id !== $item->id) {
            throw ValidationException::withMessages(['menu_item_option_id' => 'That option is not on this dish.']);
        }

        if (in_array($field, MenuDraft::OPTION_FIELDS, true) && $option === null) {
            throw ValidationException::withMessages(['menu_item_option_id' => 'Pick the option this change is for.']);
        }

        if (in_array($field, MenuDraft::DISH_FIELDS, true) && $option !== null) {
            throw ValidationException::withMessages(['field' => 'That change is for the dish, not one option.']);
        }

        if ($value !== null && $value !== '') {
            $this->checkValue($field, $value);
        }

        $this->drafts->set($item, $option, $field, $value, $request->user());

        return $this->board();
    }

    /** An option that will be created when the changes go live. */
    public function addOption(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'option_label' => ['required', 'string', 'max:100'],
            // A new option needs its own receipt name, or a bare "7 Wings"
            // prints on its own. See the receipt name rule.
            'display_name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:100000'],
        ], [
            'display_name.required' => 'Give the receipt name. It is what prints on the slip.',
        ]);

        $this->drafts->addOption(
            MenuItem::query()->findOrFail($validated['menu_item_id']),
            $validated['option_label'],
            $validated['display_name'],
            (float) $validated['price'],
            $request->user(),
        );

        return $this->board();
    }

    public function removeDraft(MenuDraft $draft): JsonResponse
    {
        $draft->delete();

        return $this->board();
    }

    public function clearDrafts(): JsonResponse
    {
        MenuDraft::query()->delete();

        return $this->board();
    }

    /**
     * Put the waiting changes live now, or set a time.
     *
     * The time is the server's. Ghana is UTC+0 all year, and so is the app,
     * so 06:00 typed on the page is 06:00 at every till.
     */
    public function publish(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'when' => ['required', Rule::in(['now', 'at'])],
            'publish_at' => ['required_if:when,at', 'nullable', 'date', 'after:now'],
        ], [
            'publish_at.after' => 'Pick a time that has not passed yet.',
        ]);

        if ($validated['when'] === 'now') {
            $this->publisher->publishNow($request->user());

            return $this->board('The changes are live.');
        }

        if (! MenuDraft::query()->exists()) {
            throw ValidationException::withMessages(['drafts' => 'Nothing is waiting to go live.']);
        }

        $at = Carbon::parse($validated['publish_at']);

        // One scheduled publish at a time. Setting a new time moves it.
        $scheduled = MenuPublication::query()
            ->where('status', MenuPublicationStatus::Scheduled->value)
            ->first();

        if ($scheduled !== null) {
            $scheduled->update(['publish_at' => $at, 'scheduled_by' => $request->user()->id]);
        } else {
            MenuPublication::create([
                'status' => MenuPublicationStatus::Scheduled,
                'publish_at' => $at,
                'scheduled_by' => $request->user()->id,
            ]);
        }

        return $this->board('The time is set.');
    }

    public function cancel(Request $request, MenuPublication $publication): JsonResponse
    {
        if ($publication->status !== MenuPublicationStatus::Scheduled) {
            throw ValidationException::withMessages(['publication' => 'Only a publish that is still waiting can be called off.']);
        }

        $publication->update([
            'status' => MenuPublicationStatus::Cancelled,
            'note' => 'Called off by '.$request->user()->name.'.',
        ]);

        return $this->board('Called off. The changes are still waiting.');
    }

    public function undo(Request $request, MenuPublication $publication): JsonResponse
    {
        $this->publisher->undo($publication, $request->user());

        return $this->board('The old values are back.');
    }

    private function checkValue(string $field, mixed $value): void
    {
        $rules = match ($field) {
            'price' => ['numeric', 'min:0', 'max:100000'],
            'option_label' => ['string', 'max:100'],
            'display_name', 'name' => ['string', 'max:255'],
            'off_sale' => ['boolean'],
        };

        validator(['value' => $value], ['value' => $rules])->validate();
    }

    private function board(?string $message = null): JsonResponse
    {
        return response()->success($this->priceBoard->build(), $message);
    }
}
