<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveShortLinkRequest;
use App\Http\Resources\ShortLinkResource;
use App\Models\ShortLink;
use App\Services\ShortLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Both ends of the shortener: the admin who makes a link, and the customer who
 * taps it.
 *
 * The public end is `resolve`. It is unauthenticated because the person tapping
 * has no account and no reason to have one, and there is nothing to protect — a
 * short link's whole job is to be followed by anybody holding it.
 */
class ShortLinkController extends Controller
{
    public function __construct(private readonly ShortLinkService $links) {}

    // ─── Public ──────────────────────────────────────────────────────────────

    /**
     * Where this token goes, counted on the way past.
     *
     * A POST rather than a GET because it writes, and because the body carries
     * the customer's user agent and referer. The only caller is our own Next.js
     * route handler, so the request Laravel sees belongs to our server — reading
     * the headers off it would record the same user agent 28,000 times.
     *
     * The click is recorded but never blocks the answer; see
     * ShortLinkService::recordClick().
     */
    public function resolve(Request $request, string $token): JsonResponse
    {
        $link = $this->links->resolve($token);

        if (! $link) {
            // One answer for "never existed" and "expired", same as recruitment
            // links. Telling them apart makes this an oracle for guessing tokens.
            return response()->error('This link is no longer active.', 404);
        }

        $this->links->recordClick(
            $link,
            $request->input('user_agent'),
            $request->input('referer'),
        );

        return response()->success([
            'target_url' => $link->target_url,
        ]);
    }

    // ─── Admin ───────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $links = ShortLink::with('createdBy')
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($q) => $q->where('label', 'like', $term)->orWhere('token', 'like', $term));
            })
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return response()->success(
            ShortLinkResource::collection($links)->response()->getData(true),
        );
    }

    public function store(SaveShortLinkRequest $request): JsonResponse
    {
        $link = $this->links->create(
            label: $request->string('label')->trim()->value(),
            targetUrl: $request->string('target_url')->trim()->value(),
            createdByUserId: $request->user()->id,
            expiresAt: $request->date('expires_at'),
        );

        return response()->created(
            (new ShortLinkResource($link->load('createdBy')))->resolve(),
        );
    }

    /**
     * Rename a link, move its expiry, or repoint it.
     *
     * Repointing is the point of a 302 — a mistyped target on a link already in
     * customers' phones is fixable here rather than being a wasted campaign.
     * Every change goes through the activity log; see ShortLink.
     */
    public function update(SaveShortLinkRequest $request, ShortLink $link): JsonResponse
    {
        $link->update($request->safe()->only(['label', 'target_url', 'expires_at']));

        return response()->success(
            (new ShortLinkResource($link->fresh('createdBy')))->resolve(),
            'Link updated.',
        );
    }

    /**
     * Delete a link, and with it every click ever recorded against it.
     *
     * Deliberately permitted even for a link that has been used: unlike a
     * recruitment posting, nothing here is somebody's personal details. Setting
     * an expiry is how a live link is retired without losing its history.
     */
    public function destroy(ShortLink $link): JsonResponse
    {
        $link->delete();

        return response()->deleted();
    }
}
