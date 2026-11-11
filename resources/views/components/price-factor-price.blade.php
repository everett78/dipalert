<span>
    <strong class="font-bold">{{ $cache->getPriceFormatted() }}</strong> {{ __('for') }}
    {{ (float) $cache->getPriceFactor() }} {{ $cache->getUnitOfMeasurePlural() ?? __('units') }}
</span>
