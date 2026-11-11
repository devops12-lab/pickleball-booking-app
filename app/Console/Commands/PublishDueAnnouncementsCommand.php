<?php

namespace App\Console\Commands;

use App\Services\AnnouncementService;
use Illuminate\Console\Command;

class PublishDueAnnouncementsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'announcements:publish-due';

    /**
     * The console command description.
     */
    protected $description = 'Publish scheduled announcements whose publish time has passed';

    /**
     * Execute the console command.
     */
    public function handle(AnnouncementService $announcements): int
    {
        $count = $announcements->publishDue();

        if ($count === 0) {
            $this->info('No scheduled announcements are due.');

            return self::SUCCESS;
        }

        $this->info("Published {$count} scheduled announcement(s).");

        return self::SUCCESS;
    }
}
