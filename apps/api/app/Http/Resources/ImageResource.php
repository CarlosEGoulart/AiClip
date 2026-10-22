<?php

namespace App\Http\Resources;

use App\Enums\ImageStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class ImageResource extends JsonResource
{
    /**
     * Strict field whitelist: storage internals (storage_disk,
     * storage_key), user_id, and bucket names are never exposed in any
     * image response.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'prompt' => $this->prompt,
            'parameters' => $this->parameters,
            'mime_type' => $this->mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'width' => (int) $this->width,
            'height' => (int) $this->height,
            'status' => $this->status instanceof ImageStatus
                ? $this->status->value
                : $this->status,
            'download_url' => $this->downloadUrl(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * 1-hour signed API URL for the image download endpoint, minted only
     * for images whose artifact exists (generated); null for pending and
     * failed rows. The URL embeds the route URI path plus signature and
     * expires parameters — never storage internals.
     */
    private function downloadUrl(): ?string
    {
        $status = $this->status instanceof ImageStatus ? $this->status->value : $this->status;

        if ($status !== ImageStatus::GENERATED->value) {
            return null;
        }

        return URL::temporarySignedRoute('image.download', now()->addHour(), ['image' => $this->id]);
    }
}
