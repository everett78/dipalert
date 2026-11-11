@props(['url'])
<tr>
<td class="header">
<a href="{{ url('/') }}" style="display: inline-block">
@php
    echo file_get_contents(public_path('images/logo-full.svg'));
@endphp
</a>
</td>
</tr>
