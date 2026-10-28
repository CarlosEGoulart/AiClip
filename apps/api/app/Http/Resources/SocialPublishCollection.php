<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class SocialPublishCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        $data = $this->collection;

        $response = [
            'data' => $data,
        ];

        if ($this->resource instanceof LengthAwarePaginator) {
            $response['links'] = $this->resource->links();
            $response['meta'] = [
                'current_page' => $this->resource->currentPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'last_page' => $this->resource->lastPage(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
            ];
        }

        return $response;
    }
}
