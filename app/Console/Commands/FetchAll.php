<?php

namespace App\Console\Commands;

use App\Services\PriceFetcherService;
use Illuminate\Console\Command;

class FetchAll extends Command
{
    const COMMAND = 'buddy:fetch-all';

    /**
     * The name and signature of the console command.
     */
    protected $signature = self::COMMAND.' {--log : Log the price fetching}';

    /**
     * The console command description.
     */
    protected $description = 'Fetch all prices';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        PriceFetcherService::new()
            ->setLogging((bool) $this->option('log'))
            ->updateAllPrices();

        return self::SUCCESS;
    }
}
