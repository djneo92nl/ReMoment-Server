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
        options: {{ json_encode($driverOptions) }},
        get brands() {
            return [...new Set(this.options.map(o => o.brand))];
        },
        get products() {
            return this.options.filter(o => o.brand === this.brand);
        },
        selectProduct(product, driver, driver_name) {
            this.product = product;
            this.driver = driver;
            this.driver_name = driver_name;
        }
    }"
    class="space-y-6">

    <!-- Device Name -->
    <div>
        <label for="device_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Device Name</label>
        <input type="text" name="device_name" id="device_name"
               value="{{ old('device_name', $device?->device_name ?? '') }}"
               placeholder="e.g. Living Room Speaker"
               required
               class="w-full rounded-xl border {{ $errors->has('device_name') ? 'border-red-400' : 'border-gray-200 dark:border-stone-700' }} dark:bg-stone-800 dark:text-gray-100 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-stone-500 focus:border-transparent transition-shadow">
        @error('device_name')
            <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>

    <!-- IP Address -->
    <div>
        <label for="ip_address" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">IP Address</label>
        <input type="text" name="ip_address" id="ip_address"
               value="{{ old('ip_address', $device?->ip_address ?? '') }}"
               placeholder="192.168.1.x"
               required
               class="w-full rounded-xl border {{ $errors->has('ip_address') ? 'border-red-400' : 'border-gray-200 dark:border-stone-700' }} dark:bg-stone-800 dark:text-gray-100 px-4 py-3 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-stone-500 focus:border-transparent transition-shadow">
        @error('ip_address')
            <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>

    <!-- Brand Selection -->
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Brand</label>
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
        @error('device_brand_name')
            <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>

    <!-- Product Selection -->
    <div x-show="brand" x-cloak>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Product Model</label>
        <div class="flex flex-wrap gap-2">
            <template x-for="option in products" :key="option.product">
                <button type="button"
                        @click="selectProduct(option.product, option.driver, option.driver_name)"
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
        @error('device_product_type')
            <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>

    <!-- Driver Info (read-only display) -->
    <div x-show="driver" x-cloak>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Driver</label>
        <div class="bg-gray-50 dark:bg-stone-800/50 rounded-xl border border-gray-200 dark:border-stone-700 px-4 py-3">
            <div class="flex items-center justify-between gap-4">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300" x-text="driver_name"></span>
                <span class="text-xs text-gray-400 dark:text-gray-600 font-mono truncate" x-text="driver"></span>
            </div>
        </div>
    </div>

</div>
