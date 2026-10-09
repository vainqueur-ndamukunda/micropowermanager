<?php

namespace App\Plugins\OpenPaygo\Console\Commands;

use App\Plugins\OpenPaygo\Services\ManufacturerService;
use Illuminate\Console\Command;

class InstallPackage extends Command {
    protected $signature = 'openpaygo:install';
    protected $description = 'Register the OpenPAYGO manufacturer API';

    public function __construct(private ManufacturerService $manufacturerService) {
        parent::__construct();
    }

    public function handle(): void {
        $this->manufacturerService->register();
        $this->info('OpenPAYGO manufacturer API registered.');
    }
}
