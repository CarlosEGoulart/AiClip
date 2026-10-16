<?php

namespace App\Events;

use App\Models\ClipReview;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClipReviewCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ClipReview $clipReview,
    ) {}
}