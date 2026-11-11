<?php

namespace Database\Seeders;

use App\Models\Store;
use Exception;
use Illuminate\Database\Seeder;

class StoreSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::getStoreData() as $storeData) {
            Store::factory()->createOne($storeData);
        }
    }

    public static function getStoreData(?string $country = null): array
    {
        $basePath = __DIR__.'/Stores/';

        if (! is_null($country)) {
            if (file_exists($basePath.$country.'.php')) {
                return require $basePath.$country.'.php';
            } else {
                throw new Exception('Country file not found');
            }
        }

        $all = [];
        foreach (glob($basePath.'/*.php') as $filename) {
            $all = array_merge($all, require $filename);
        }

        return $all;
    }

    public static function getAllCountries(): array
    {
        $basePath = __DIR__.'/Stores/';
        $countries = [];

        foreach (glob($basePath.'/*.php') as $filename) {
            $countries[] = basename($filename, '.php');
        }

        return $countries;
    }
}
