<?php

namespace App\Console\Commands;

use App\Exceptions\OverdueServiceUnavailableException;
use App\Services\Overdue\OverdueServiceClient;
use Illuminate\Console\Command;

class SyncOverdueTasks extends Command
{
    protected $signature = 'tasks:sync-overdue';

    protected $description = 'Mark past-due, unfinished tasks as OVERDUE via the Django overdue service';

    public function handle(OverdueServiceClient $client): int
    {
        try {
            $updated = $client->sync();
        } catch (OverdueServiceUnavailableException $e) {
            $this->error('Overdue service unavailable: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$updated} task(s) marked as OVERDUE.");

        return self::SUCCESS;
    }
}
