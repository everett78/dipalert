<?php

namespace App\Filament\Concerns;

use App\Enums\AiFeature;
use App\Enums\FetchOutcome;
use App\Enums\Icons;
use App\Enums\ScraperService;
use App\Enums\ScraperStrategyType;
use App\Services\Helpers\IntegrationHelper;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Illuminate\Support\HtmlString;

trait HasScraperTrait
{
    protected static function makeStrategyInput(string $key, ?string $default = null, bool $required = true): array
    {
        $typeField = Select::make($key.'.type')
            ->label('Type')
            ->options(ScraperStrategyType::class)
            ->default(ScraperStrategyType::Selector->value)
            ->hintIcon(Icons::Help->value, 'How to get the value')
            ->live();

        $valueField = TextInput::make($key.'.value')
            ->label('Value')
            ->default($default)
            ->hintIcon(Icons::Help->value, fn (Get $get) => ScraperStrategyType::getValueHelp($get($key.'.type')))
            ->live();

        if ($required) {
            $typeField = $typeField->required();
            $valueField = $valueField->required();
        }

        $valueField = $valueField->hidden(fn (Get $get) => ! ScraperStrategyType::needsValue($get($key.'.type')));

        return [
            $typeField,
            $valueField,
            TextInput::make($key.'.prepend')
                ->label('Prepend')
                ->hidden(fn (Get $get) => ! ScraperStrategyType::needsValue($get($key.'.type')))
                ->hintIcon(Icons::Help->value, 'Optionally prepend a static value to the extracted value'),
            TextInput::make($key.'.append')
                ->label('Append')
                ->hidden(fn (Get $get) => ! ScraperStrategyType::needsValue($get($key.'.type')))
                ->hintIcon(Icons::Help->value, 'Optionally append a static value to the extracted value'),
        ];
    }

    protected static function getScraperSettings(): Section
    {
        return Section::make('Scraper service')->schema([
            Radio::make('settings.scraper_service')
                ->options(ScraperService::class)
                ->descriptions([
                    ScraperService::Http->value => 'Faster and less resource intensive. Use this for JSON strategy',
                    ScraperService::Api->value => 'Slower but good for scraping JavaScript rendered pages',
                    ScraperService::FlareSolverr->value => __('Slower; a real browser through FlareSolverr. Use when a store blocks plain requests (e.g. Allegro).'),
                ])
                ->reactive()
                ->default(ScraperService::Http)
                ->helperText(fn (Get $get): ?string => self::scraperServiceHint($get)),

            // Kept in the form so a save does not drop them.
            Hidden::make('settings.suggested_scraper_service'),
            Hidden::make('settings.last_test_outcome'),

            TextInput::make('settings.min_interval_seconds')
                ->label(__('Interval between requests (s)'))
                ->helperText(__('Minimum time between two requests to this store.'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(600)
                ->placeholder((string) config('dipalert.default_min_interval_seconds', 10)),

            Textarea::make('settings.scraper_service_settings')
                ->label('Settings')
                ->hint(new HtmlString('One option per line. <a href="https://github.com/jez500/seleniumbase-scrapper#api-endpoints" target="_blank">Read docs</a>'))
                ->hidden(fn (Get $get) => $get('settings.scraper_service') !== ScraperService::Api->value)
                ->rows(4)
                ->placeholder("device=Desktop Firefox\nsleep=1000"),

            Toggle::make('settings.missing_price_out_of_stock')
                ->label('Treat a missing price as out of stock')
                ->helperText('For stores that remove the price when a product is out of stock (e.g. Amazon). A page with a title but no price is marked out of stock instead of failing. A broken price selector will also look like out of stock.')
                ->columnSpanFull(),

            Toggle::make('settings.ai_extraction_enabled')
                ->label('Enable AI price extraction')
                ->helperText('Use AI to recover a price when the normal scrape finds none.')
                ->live()
                ->hidden(fn (): bool => ! IntegrationHelper::isFeatureEnabled(AiFeature::Extraction))
                ->columnSpanFull(),

            Select::make('settings.ai_provider_id')
                ->label('AI provider')
                ->options(fn (): array => collect(IntegrationHelper::getAiProviders())
                    ->mapWithKeys(fn ($provider): array => [$provider->id => $provider->name])
                    ->all())
                ->default(fn (): ?string => IntegrationHelper::getActiveAiProvider()?->id)
                ->required(fn (Get $get): bool => (bool) $get('settings.ai_extraction_enabled'))
                ->visible(fn (Get $get): bool => (bool) $get('settings.ai_extraction_enabled'))
                ->columnSpanFull(),

            Toggle::make('settings.ai_self_healing_disabled')
                ->label('Disable AI self-healing for this store')
                ->helperText('Prevent AI from automatically repairing this store\'s scraper config when a scrape fails.')
                ->hidden(fn (): bool => ! IntegrationHelper::isFeatureEnabled(AiFeature::Healing))
                ->columnSpanFull(),
        ])->description('Advanced scraper service settings')->columns(2);
    }

    /**
     * Suggest FlareSolverr when the last test was blocked or AI repair proposed it.
     */
    protected static function scraperServiceHint(Get $get): ?string
    {
        if ($get('settings.scraper_service') === ScraperService::FlareSolverr->value) {
            return null;
        }

        $blocked = $get('settings.last_test_outcome') === FetchOutcome::Blocked->value;
        $suggested = $get('settings.suggested_scraper_service') === ScraperService::FlareSolverr->value;

        if (! $blocked && ! $suggested) {
            return null;
        }

        return $blocked
            ? __('The store blocked the last request. Choose FlareSolverr and test again.')
            : __('AI repair suggests FlareSolverr for this store. Choose it and test again.');
    }
}
