<?php

namespace Plugin\TutorialImporter\Commands;

use Illuminate\Console\Command;
use Plugin\TutorialImporter\Services\TutorialImportService;
use Illuminate\Support\Facades\Log;

class ImportTutorial extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tutorial:import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import tutorial markdown files into Xboard Knowledge Base';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Starting tutorial import...');

        try {
            $service = new TutorialImportService();
            $results = $service->import();

            $this->info("Import completed successfully.");
            $this->info("Total: {$results['total']}");
            $this->info("Success: {$results['success']}");
            $this->info("Failed: {$results['failed']}");

            if (!empty($results['errors'])) {
                $this->error("Errors:");
                foreach ($results['errors'] as $error) {
                    $this->error("- $error");
                }
            }
        } catch (\Exception $e) {
            $this->error('Import failed: ' . $e->getMessage());
            Log::error('Tutorial Import Failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
