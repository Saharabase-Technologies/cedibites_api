<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;

/**
 * A page of orders, in the shape the app has always expected.
 *
 * This returned `parent::toArray()`, a bare list, and the macro that sends it
 * is `response()->json($data)`. Passing a resource collection through
 * `response()->json()` serialises it with `jsonSerialize()`, which is just
 * `toArray()`, so the `data` / `meta` / `links` wrapper Laravel adds when a
 * resource is returned straight from a controller never appeared.
 *
 * The customer app reads `ordersData?.data`, so it was reading `.data` off an
 * array and getting undefined every time. My Orders showed nothing to
 * everybody, always, however many orders were sitting behind the number. The
 * data was never the problem: orders have been tied to the phone correctly all
 * along, and this endpoint was answering 200 with every one of them.
 *
 * `MenuItemCollection` next door already wraps in `data` for the same reason.
 * The pagination figures are added here too, because the list reads `meta` to
 * know whether there is another page.
 */
class OrderCollection extends ResourceCollection
{
    /**
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = ['data' => $this->collection];

        // Only a paginated query has pages. A plain collection passed here
        // should not grow invented page numbers.
        if ($this->resource instanceof AbstractPaginator) {
            $payload['meta'] = [
                'current_page' => $this->resource->currentPage(),
                'from' => $this->resource->firstItem(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'to' => $this->resource->lastItem(),
                'total' => $this->resource->total(),
            ];

            $payload['links'] = [
                'first' => $this->resource->url(1),
                'last' => $this->resource->url($this->resource->lastPage()),
                'prev' => $this->resource->previousPageUrl(),
                'next' => $this->resource->nextPageUrl(),
            ];
        }

        return $payload;
    }
}
