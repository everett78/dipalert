<?php

namespace App\Console\Commands;

use App\Services\SearchService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;

use function Laravel\Prompts\text;

class BuildSearchResearch extends Command implements PromptsForMissingInput
{
    const COMMAND = 'buddy:build-search-research';

    /**
     * The name and signature of the console command.
     */
    protected $signature = self::COMMAND.' {product_name : The name of the product}';

    /**
     * The console command description.
     */
    protected $description = 'Build search research cache for a product';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $productName = $this->argument('product_name');

        $this->getOutput()->title('Search for: '.$productName);

        $service = SearchService::new($productName)
            ->setUseLaravelLog(true);

        try {
            $service->build($productName);

            $this->getOutput()->success('Done building search cache');
        } catch (Exception $e) {
            $this->getOutput()->error('Failed to build search cache: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'product_name' => fn () => text(
                label: 'What is the product you want to search for',
            ),
        ];
    }
}
