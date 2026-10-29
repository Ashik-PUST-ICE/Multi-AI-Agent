<?php

namespace App\Jobs;

use App\Models\UserNotification;
use App\Services\NotificationDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverUserNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $notificationId) {}

    public function handle(NotificationDeliveryService $service): void
    {
        $notification = UserNotification::query()->with('user')->findOrFail($this->notificationId);
        $service->deliver($notification);
    }
}
