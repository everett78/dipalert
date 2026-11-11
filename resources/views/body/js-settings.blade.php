@php
use App\Services\Helpers\CurrencyHelper;
@endphp
<script>
    window.jsSettings = @js(cache()->rememberForever('body.js-settings', fn () => [
        'locale' => str_replace('_', '-', CurrencyHelper::getLocale()),
        'currency' => CurrencyHelper::getCurrency(),
    ]))
</script>
