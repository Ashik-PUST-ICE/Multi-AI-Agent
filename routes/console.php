<?php

use App\Console\CliApiClient;
use App\Console\CliApiException;
use App\Console\LocalConnectorFileService;
use App\Console\LocalConnectorPolicy;
use App\Models\Task;
use App\Services\NotificationMaintenanceService;
use App\Services\TaskScheduleService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command;

Artisan::command('notifications:maintain', function (NotificationMaintenanceService $service): void {
    $this->info('Notification maintenance processed '.$service->run().' reminder(s).');
})->purpose('Expire unanswered approvals and process reminders and policy renewal notices');

Artisan::command('tasks:dispatch-scheduled', function (TaskScheduleService $service): void {
    $this->info('Dispatched '.$service->dispatchDue().' scheduled task occurrence(s).');
})->purpose('Queue due owner-configured task schedules');

Artisan::command('assistant:purge-vision-images', function (): void {
    $purged = 0;
    Task::withoutGlobalScopes()
        ->withTrashed()
        ->whereNotNull('vision_image_path')
        ->where('vision_image_expires_at', '<=', now())
        ->orderBy('id')
        ->chunkById(100, function ($tasks) use (&$purged): void {
            foreach ($tasks as $task) {
                if (! Storage::disk('local')->delete($task->vision_image_path)) {
                    throw new RuntimeException('An expired private vision attachment could not be deleted.');
                }

                $task->forceFill([
                    'vision_image_path' => null,
                    'vision_image_mime_type' => null,
                    'vision_image_expires_at' => null,
                ])->saveQuietly();
                $purged++;
            }
        });

    $this->info("Purged {$purged} expired private vision image(s).");
})->purpose('Delete private camera images after their retention period');

Artisan::command('connector:status {--online}', function (LocalConnectorPolicy $policy, CliApiClient $client): int {
    try {
        $status = ['policy' => $policy->policy(), 'kill_switch_active' => false];
        try {
            $policy->assertLiveMode(true);
        } catch (RuntimeException) {
            $status['kill_switch_active'] = true;
        }
        if ($this->option('online')) {
            $status['api'] = $client->request('GET', '/user')['user'] ?? null;
        }
        $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }
})->purpose('Show local connector policy and optional API pairing status');

Artisan::command('connector:allow-folder {path}', function (LocalConnectorPolicy $policy): int {
    try {
        $resolved = $policy->addAllowedFolder((string) $this->argument('path'));
        $this->info('Allowlisted folder: '.$resolved);

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }
})->purpose('Add an existing folder to the local connector allow-list');

Artisan::command('connector:remove-folder {path}', function (LocalConnectorPolicy $policy): int {
    try {
        $policy->removeAllowedFolder((string) $this->argument('path'));
        $this->info('Removed the folder from the local connector allow-list.');

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }
})->purpose('Remove a folder from the local connector allow-list');

Artisan::command('connector:capability {capability} {--enable} {--disable}', function (LocalConnectorPolicy $policy): int {
    try {
        $capability = (string) $this->argument('capability');
        if ((bool) $this->option('enable') === (bool) $this->option('disable')) {
            throw new RuntimeException('Specify exactly one of --enable or --disable.');
        }
        if ($this->option('enable')) {
            if (! $this->confirm("Enable local capability [{$capability}]? Dangerous folders remain blocked.", false)) {
                return 3;
            }
            $policy->enableCapability($capability);
        } else {
            $policy->disableCapability($capability);
        }
        $this->info('Capability policy updated.');

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }
})->purpose('Explicitly enable or disable a local connector capability');

Artisan::command('connector:trust-level {level}', function (LocalConnectorPolicy $policy): int {
    try {
        $level = filter_var($this->argument('level'), FILTER_VALIDATE_INT);
        if (! is_int($level) || $level < 0 || $level > AGENT_TRUST_LEVEL_SENSITIVE) {
            throw new RuntimeException('Trust level must be an integer from 0 to 4.');
        }
        if ($level > 0 && ! $this->confirm("Set this device's local Trust Level to {$level}?", false)) {
            return 3;
        }
        $policy->setTrustLevel($level);
        $this->info("Local connector Trust Level set to {$level}.");

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }
})->purpose('Set the local device trust ceiling used while offline');

Artisan::command('connector:file-read {path} {--live}', function (LocalConnectorFileService $files): int {
    try {
        $result = $files->read((string) $this->argument('path'), (bool) $this->option('live'));
        if (($result['simulated'] ?? false) === true) {
            $this->line('Sandbox: would read '.$result['path'].' ('.$result['bytes'].' bytes). No file content was read.');

            return Command::SUCCESS;
        }
        $this->output->write($result['content']);

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }
})->purpose('Read an allowlisted local text file; default is dry-run');

Artisan::command('connector:file-write {path} {content} {--live}', function (LocalConnectorFileService $files): int {
    try {
        $live = (bool) $this->option('live');
        $path = (string) $this->argument('path');
        $content = (string) $this->argument('content');
        $result = $files->write($path, $content, false);
        $this->line($result['diff']);
        if (! $live) {
            $this->info('Sandbox dry-run only. No file was changed.');

            return Command::SUCCESS;
        }
        if (! $this->confirm('Apply this diff to the allowlisted file?', false)) {
            $this->warn('File was not changed.');

            return 3;
        }

        $result = $files->write($path, $content, true, $result['base_sha256']);
        $this->info('File updated.'.($result['checkpoint'] === null ? '' : ' Checkpoint: '.$result['checkpoint']));

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }
})->purpose('Preview a local file diff; only apply with --live and explicit confirmation');

Artisan::command('connector:file-trash {path} {--live}', function (LocalConnectorFileService $files): int {
    try {
        $path = (string) $this->argument('path');
        $live = (bool) $this->option('live');
        if ($live && ! $this->confirm('Move this file to local connector Trash?', false)) {
            return 3;
        }
        $result = $files->trash($path, $live);
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }
})->purpose('Preview or move an allowlisted file to recoverable local Trash');

Artisan::command('connector:file-restore {trash_id}', function (LocalConnectorFileService $files): int {
    try {
        if (! $this->confirm('Restore this Trash item without overwriting an existing file?', false)) {
            return 3;
        }
        $this->line(json_encode(
            $files->restore((string) $this->argument('trash_id')),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }
})->purpose('Restore a local connector Trash item without overwriting existing files');

Artisan::command('connector:kill-switch', function (LocalConnectorPolicy $policy): int {
    try {
        $policy->activateKillSwitch();
        $this->warn('Local connector kill switch is active. No enabled capability can run.');

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }
})->purpose('Immediately disable all local connector operations');

Artisan::command('connector:resume', function (LocalConnectorPolicy $policy): int {
    try {
        if (! $this->confirm('Deactivate the local connector kill switch?', false)) {
            return 3;
        }
        $policy->deactivateKillSwitch();
        $this->info('Local connector kill switch deactivated.');

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }
})->purpose('Require confirmation before resuming local connector operations');

Artisan::command('agent:login {--api= : HTTPS API base URL} {--name=CLI : Name for this device token}', function (CliApiClient $client): int {
    try {
        $apiUrl = (string) ($this->option('api') ?: config('app.url'));
        $deviceName = (string) $this->option('name');
        $authorization = $client->requestDeviceCode($apiUrl, $deviceName);
        $verificationUrl = $authorization['verification_uri'];
        $this->line('Open this URL in your browser: '.$verificationUrl);
        $this->line('Confirm the code matches: <options=bold>'.$authorization['user_code'].'</>');
        $this->line('Your password is entered only in the browser, never in the CLI.');

        $deadline = time() + (int) $authorization['expires_in'];
        do {
            sleep((int) $authorization['interval']);
            try {
                $response = $client->pollDeviceToken($apiUrl, $authorization['device_code']);
                $client->saveCredentials($apiUrl, $response['token']);
                $this->info('CLI device authorized and credentials saved for this user account.');

                return Command::SUCCESS;
            } catch (CliApiException $exception) {
                if ($exception->statusCode !== 428) {
                    throw $exception;
                }
                $this->output->write('.');
            }
        } while (time() < $deadline);

        $this->newLine();
        $this->error('Device authorization expired. Start agent:login again.');

        return Command::FAILURE;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }
})->purpose('Sign in to the CLI using browser-approved device authorization');

Artisan::command('agent:logout', function (CliApiClient $client): int {
    try {
        try {
            $client->request('POST', '/auth/logout');
        } catch (CliApiException $exception) {
            if ($exception->statusCode !== 401) {
                throw $exception;
            }
            $client->clearCredentials();
            $this->warn('The server token was already invalid; local credentials were removed.');

            return Command::SUCCESS;
        }
        $client->clearCredentials();
        $this->info('CLI token revoked and local credentials removed.');

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }
})->purpose('Revoke the current CLI token and remove local credentials');

Artisan::command('agent:ask {prompt} {--api=} {--json}', function (CliApiClient $client): int {
    try {
        $prompt = trim((string) $this->argument('prompt'));
        $estimate = $client->request('POST', '/tasks/estimate', ['prompt' => $prompt], $this->option('api'));
        $review = $estimate['task_brief_review'] ?? [];
        if (($review['required'] ?? false) === true) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'state' => 'review_required',
                    'task_brief' => $estimate['task_brief'] ?? null,
                    'message' => 'Interactive TaskBrief review is required. Rerun without --json to review.',
                ], JSON_THROW_ON_ERROR));

                return 3;
            }
            $this->line(json_encode($estimate['task_brief'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (! $this->confirm('Approve this TaskBrief and send the request?', false)) {
                return 3;
            }
        }

        $response = $client->request('POST', '/assistant/messages', [
            'content' => $prompt,
            'task_brief_review_token' => $review['token'] ?? null,
        ], $this->option('api'));
        if ($this->option('json')) {
            $this->line(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } elseif (is_string($response['assistant_message']['content'] ?? null)) {
            $this->line($response['assistant_message']['content']);
        } else {
            $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        if ($this->option('json')) {
            $this->line(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
        } else {
            $this->error($exception->getMessage());
        }

        return Command::FAILURE;
    }
})->purpose('Ask the assistant through the versioned API');

Artisan::command('agent:run {prompt} {--api=} {--json}', function (CliApiClient $client): int {
    try {
        $prompt = trim((string) $this->argument('prompt'));
        $estimate = $client->request('POST', '/tasks/estimate', ['prompt' => $prompt], $this->option('api'));
        $review = $estimate['task_brief_review'] ?? [];
        if (($review['required'] ?? false) === true) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'state' => 'review_required',
                    'task_brief' => $estimate['task_brief'] ?? null,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return 3;
            }
            $this->line(json_encode($estimate['task_brief'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (! $this->confirm('Approve this TaskBrief and queue the task?', false)) {
                return 3;
            }
        }

        $submission = $client->request('POST', '/tasks', [
            'title' => mb_substr($prompt, 0, 255),
            'prompt' => $prompt,
            'task_brief_review_token' => $review['token'] ?? null,
        ], $this->option('api'));
        $taskId = (int) ($submission['task']['id'] ?? 0);
        if ($taskId < 1) {
            throw new RuntimeException('The API accepted no task identifier.');
        }
        if (! $this->option('json')) {
            $this->info("Task #{$taskId} queued; streaming owner-scoped progress.");
        }
        $task = $client->streamTask($taskId, $this->output, (bool) $this->option('json'));
        if ($this->option('json')) {
            $this->line(json_encode(['task' => $task], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line(json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return (int) ($task['status'] ?? -1) === TASK_STATUS_COMPLETED
            ? Command::SUCCESS
            : Command::FAILURE;
    } catch (Throwable $exception) {
        if ($this->option('json')) {
            $this->line(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
        } else {
            $this->error($exception->getMessage());
        }

        return Command::FAILURE;
    }
})->purpose('Create a Sandbox task and stream its progress');

Artisan::command('agent:status {task_id} {--api=} {--json}', function (CliApiClient $client): int {
    try {
        $task = $client->request('GET', '/tasks/'.(int) $this->argument('task_id'), [], $this->option('api'))['task'];
        if ($this->option('json')) {
            $this->line(json_encode(['task' => $task], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line(json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return (int) $task['status'] === TASK_STATUS_FAILED
            ? Command::FAILURE
            : Command::SUCCESS;
    } catch (Throwable $exception) {
        if ($this->option('json')) {
            $this->line(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
        } else {
            $this->error($exception->getMessage());
        }

        return Command::FAILURE;
    }
})->purpose('Show the current status and result of an owned task');

Artisan::command('agent:approvals {--api=} {--json}', function (CliApiClient $client): int {
    try {
        $data = $client->request('GET', '/approvals', [], $this->option('api'));
        $this->line($this->option('json')
            ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        if ($this->option('json')) {
            $this->line(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
        } else {
            $this->error($exception->getMessage());
        }

        return Command::FAILURE;
    }
})->purpose('List pending tool approvals for the authenticated user');

Artisan::command('agent:approve {approval_id} {--api=} {--yes} {--json}', function (CliApiClient $client): int {
    try {
        $approvalId = (int) $this->argument('approval_id');
        $pending = $client->request('GET', '/approvals', [], $this->option('api'))['approvals'] ?? [];
        $approval = collect($pending)->firstWhere('id', $approvalId);
        if (! is_array($approval)) {
            throw new RuntimeException('That approval is not pending or is not owned by this account.');
        }
        if ($this->option('json') && ! $this->option('yes')) {
            $this->line(json_encode([
                'state' => 'confirmation_required',
                'approval' => $approval,
            ], JSON_THROW_ON_ERROR));

            return 3;
        }
        if (! $this->option('yes')) {
            $this->line(json_encode($approval, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (! $this->confirm('Approve this exact pending tool request?', false)) {
                return 3;
            }
        }
        $data = $client->request('POST', '/approvals/'.$approvalId.'/approve', [], $this->option('api'));
        $result = ['approval' => $approval, 'decision' => $data];
        $this->line($this->option('json')
            ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        if ($this->option('json')) {
            $this->line(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
        } else {
            $this->error($exception->getMessage());
        }

        return Command::FAILURE;
    }
})->purpose('Approve one exact pending tool request with explicit confirmation');

Artisan::command('agent:notifications {--api=} {--json}', function (CliApiClient $client): int {
    try {
        $data = $client->request('GET', '/notifications', [], $this->option('api'));
        $this->line($this->option('json')
            ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        if ($this->option('json')) {
            $this->line(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
        } else {
            $this->error($exception->getMessage());
        }

        return Command::FAILURE;
    }
})->purpose('List the authenticated user’s notifications');

Schedule::command('assistant:purge-vision-images')->daily();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
