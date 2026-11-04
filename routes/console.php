<?php

use App\Services\NotificationMaintenanceService;
use App\Services\TaskScheduleService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('notifications:maintain', function (NotificationMaintenanceService $service): void {
    $this->info('Notification maintenance processed '.$service->run().' reminder(s).');
})->purpose('Expire unanswered approvals and process reminders and policy renewal notices');

Artisan::command('tasks:dispatch-scheduled', function (TaskScheduleService $service): void {
    $this->info('Dispatched '.$service->dispatchDue().' scheduled task occurrence(s).');
})->purpose('Queue due owner-configured task schedules');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
