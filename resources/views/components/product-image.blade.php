<span>
    <img
        src="{{ $product->primary_image }}" alt="{{ $product->title }}"
        onerror="this.onerror=null;this.src='/images/placeholder.png';"
        {{ $attributes->merge(['class' => 'rounded-md display-block h-auto block w-20']) }}
    />
</span>
