<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Creating a short link, and repointing one.
 *
 * Both use the same rules. Repointing is deliberately allowed — it is half the
 * argument for answering with a 302 rather than a 301, because a link already
 * printed on 28,000 handsets is the one you most need to be able to fix.
 *
 * On PATCH every field is optional, so an operator can rename a link without
 * restating where it goes.
 */
class SaveShortLinkRequest extends FormRequest
{
    /** Route gates on `manage_campaigns`; the rest is validation. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'label' => [$required, 'string', 'max:255'],

            // http/https only. Without the scheme restriction this accepts
            // `javascript:` and `data:`, and our branded domain becomes a way of
            // running someone else's script behind our name.
            'target_url' => [$required, 'string', 'max:2048', 'url:http,https'],

            // Optional. A promo link that outlives the promo sends people to a
            // dead page, but plenty of links are meant to be permanent.
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $target = (string) $this->input('target_url');

            if ($target === '' || $v->errors()->has('target_url')) {
                return;
            }

            // A link pointing at the shortener is an infinite redirect: the
            // handler resolves it, sends the customer back to /r/…, and the
            // browser gives up. Cheap to typo, invisible until a campaign is out.
            if (preg_match('#^/r/#', (string) parse_url($target, PHP_URL_PATH))) {
                $host = strtolower((string) parse_url($target, PHP_URL_HOST));
                $ownHosts = array_map('strtolower', (array) config('short_links.own_hosts', []));

                if (in_array($host, $ownHosts, true)) {
                    $v->errors()->add('target_url', 'That points back at the shortener, which would loop forever.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'label.required' => 'Give the link a name so you can find it later.',
            'target_url.required' => 'Where should this link take people?',
            'target_url.url' => 'That is not a web address. It needs to start with http:// or https://.',
            'expires_at.after' => 'An expiry in the past would kill the link immediately.',
        ];
    }
}
