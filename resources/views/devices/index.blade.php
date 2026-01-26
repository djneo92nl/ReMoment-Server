<x-app-layout>
    <div class="py-12">
        <div class="max-w-7xl mx-auto px-6 lg:px-8 space-y-6">

            @foreach($devices as $device)
                @include('components.device.list-view')
            @endforeach
        </div>
    </div>
</x-app-layout>
