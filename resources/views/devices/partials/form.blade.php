@php
    // Flatten config into a JS-friendly structure for Alpine.js cascading
    $driverOptions = [];
    foreach ($driverConfig as $brand => $products) {
        foreach ($products as $product => $config) {
            $driverOptions[] = [
                'brand'       => $brand,
                'product'     => $product,
                'driver'      => $config['driver'],
                'driver_name' => $config['driver_name'],
                'virtual'     => $config['virtual'] ?? false,
            ];
        }
    }
    $brands = array_unique(array_column($driverOptions, 'brand'));
@endphp

<div
    x-data="{
        brand: '{{ old('device_brand_name', $device?->device_brand_name ?? '') }}',
        product: '{{ old('device_product_type', $device?->device_product_type ?? '') }}',
        driver: '{{ old('device_driver', $device?->device_driver ?? '') }}',
        driver_name: '{{ old('device_driver_name', $device?->device_driver_name ?? '') }}',
        virtual: false,
        options: {{ json_encode($driverOptions) }},
        get brands() {
            return [...new Set(this.options.map(o => o.brand))];
        },
        get products() {
            return this.options.filter(o => o.brand === this.brand);
        },
        selectProduct(option) {
            this.product = option.product;
            this.driver = option.driver;
            this.driver_name = option.driver_name;
            this.virtual = option.virtual ?? false;
        }
    }"
    class="space-y-6">

    <!-- Device Name -->
    <div>
        <x-input-label for="device_name" value="Device Name" class="mb-2" />
        <x-text-input type="text" name="device_name" id="device_name"
                       value="{{ old('device_name', $device?->device_name ?? '') }}"
                       placeholder="e.g. Living Room Speaker"
                       required
                       :invalid="$errors->has('device_name')"
                       class="w-full py-3" />
        <x-input-error :messages="$errors->get('device_name')" class="mt-1.5" />
    </div>

    <!-- IP Address (hidden for virtual devices) -->
    <div x-show="!virtual">
        <x-input-label for="ip_address" value="IP Address" class="mb-2" />
        <x-text-input type="text" name="ip_address" id="ip_address"
                       value="{{ old('ip_address', $device?->ip_address ?? '') }}"
                       placeholder="192.168.1.x"
                       x-bind:required="!virtual"
                       :invalid="$errors->has('ip_address')"
                       class="w-full py-3 font-mono" />
        <x-input-error :messages="$errors->get('ip_address')" class="mt-1.5" />
    </div>

    <!-- Brand Selection -->
    <div>
        <x-input-label value="Brand" class="mb-2" />
        <div class="flex flex-wrap gap-2">
            <template x-for="b in brands" :key="b">
                <button type="button"
                        @click="brand = b; product = ''; driver = ''; driver_name = ''"
                        :class="brand === b
                            ? 'bg-gray-900 dark:bg-stone-600 text-white'
                            : 'bg-gray-100 dark:bg-stone-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-stone-700'"
                        class="px-4 py-2 rounded-xl text-sm font-medium transition-colors"
                        x-text="b"></button>
            </template>
        </div>
        <input type="hidden" name="device_brand_name" :value="brand">
        <x-input-error :messages="$errors->get('device_brand_name')" class="mt-1.5" />
    </div>

    <!-- Product Selection -->
    <div x-show="brand" x-cloak>
        <x-input-label value="Product Model" class="mb-2" />
        <div class="flex flex-wrap gap-2">
            <template x-for="option in products" :key="option.product">
                <button type="button"
                        @click="selectProduct(option)"
                        :class="product === option.product
                            ? 'bg-gray-900 dark:bg-stone-600 text-white'
                            : 'bg-gray-100 dark:bg-stone-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-stone-700'"
                        class="px-4 py-2 rounded-xl text-sm font-medium transition-colors"
                        x-text="option.product"></button>
            </template>
        </div>
        <input type="hidden" name="device_product_type" :value="product">
        <input type="hidden" name="device_driver" :value="driver">
        <input type="hidden" name="device_driver_name" :value="driver_name">
        <x-input-error :messages="$errors->get('device_product_type')" class="mt-1.5" />
    </div>

    <!-- Driver Info (read-only display) -->
    <div x-show="driver" x-cloak>
        <x-input-label value="Driver" class="mb-2" />
        <div class="bg-gray-50 dark:bg-stone-800/50 rounded-xl border border-gray-200 dark:border-stone-700 px-4 py-3">
            <div class="flex items-center justify-between gap-4">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300" x-text="driver_name"></span>
                <span class="text-xs text-gray-400 dark:text-gray-600 font-mono truncate" x-text="driver"></span>
            </div>
        </div>
    </div>

</div>
