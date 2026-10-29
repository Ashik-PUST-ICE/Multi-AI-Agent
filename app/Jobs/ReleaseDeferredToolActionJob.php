<?php

namespace App\Jobs;

use App\Services\DeferredToolActionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReleaseDeferredToolActionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $deferredActionId) {}

    public function handle(DeferredToolActionService $service): void
    {
        $service->release($this->deferredActionId);
    }
}
