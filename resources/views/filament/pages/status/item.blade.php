<div
    class="flex justify-between py-2 border-b border-gray-200 dark:border-gray-700 border-dotted last:border-b-0"
>
    <span>{{ Str::of($key)->replace('_', ' ')->title() }}</span>
    <code class="whitespace-pre text-sm border border-gray-300 bg-gray-200 dark:border-gray-700 dark:bg-gray-800 rounded-sm px-1">{{ is_string($val) ? $val : json_encode($val, JSON_PRETTY_PRINT) }}</code>
</div>
