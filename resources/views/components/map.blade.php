@props([
    'features' => ['type' => 'FeatureCollection', 'features' => []],
    'center' => [151.2093, -33.8688],
    'zoom' => 9,
])

{{-- MapLibre owns this DOM. wire:ignore stops Livewire morphing it; push data with $this->dispatch('map-features', features: $geojson). --}}
<div
    wire:ignore
    x-data="nswMap(@js($features), @js($center), @js($zoom))"
    x-on:map-features.window="setFeatures($event.detail.features)"
    {{ $attributes->merge(['class' => 'relative']) }}
>
    {{-- MapLibre sets position: relative on its container, so the absolute sizing box wraps it. --}}
    <div class="absolute inset-0">
        <div x-ref="canvas" class="size-full" data-test="map-canvas"></div>
    </div>
</div>
