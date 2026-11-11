<?php

namespace App\Console\Commands;

use App\Models\Store;
use Database\Seeders\StoreSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

use function Laravel\Prompts\multiselect;

class CreateStores extends Command implements PromptsForMissingInput
{
    const COMMAND = 'buddy:create-stores';

    /**
     * The name and signature of the console command.
     */
    protected $signature = self::COMMAND.' {country : The country to create stores for, use "all" to create for all countries}'.
        ' {--update : If a store exists with the same slug, update it to match defaults}';

    /**
     * The console command description.
     */
    protected $description = 'Create stores';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $country = $this->argument('country');
        $countries = $country === 'all' ? StoreSeeder::getAllCountries() : $country;

        foreach (Arr::wrap($countries) as $country) {
            $stores = StoreSeeder::getStoreData($country);
            foreach ($stores as $storeData) {
                $slug = $storeData['slug'] ?? Str::slug($storeData['name']);

                if ($store = Store::where('slug', $slug)->first()) {
                    if ($this->option('update')) {
                        $this->components->task('Updating - '.$storeData['name'], fn () => $store->update($storeData));
                    } else {
                        $this->info('Skipping - '.$storeData['name']);
                    }
                } else {
                    $this->components->task('Creating - '.$storeData['name'], fn () => Store::factory()->createOne($storeData));
                }
            }

        }

        return self::SUCCESS;
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'country' => fn () => multiselect(
                label: 'Which country would you like to create stores for?',
                options: StoreSeeder::getAllCountries(),
            ),
        ];
    }
}
