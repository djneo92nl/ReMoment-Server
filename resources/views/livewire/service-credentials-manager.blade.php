@php
    $dot = ['ok' => 'bg-emerald-500', 'warning' => 'bg-amber-500', 'error' => 'bg-red-500', 'disabled' => 'bg-gray-400'];
    $text = ['ok' => 'text-emerald-600 dark:text-emerald-400', 'warning' => 'text-amber-600 dark:text-amber-500', 'error' => 'text-red-600 dark:text-red-500', 'disabled' => 'text-gray-500'];
@endphp

<div class="space-y-6">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Every install uses its own keys. A value saved here wins over the <code>.env</code> file, which keeps working as the fallback.
        Keys are stored encrypted, never shown again and left out of backups.
    </p>

    @foreach($services as $id => $s)
        @php($service = $s['service'])
        <x-card class="p-6 md:p-8" wire:key="service-{{ $id }}" x-data="{ help: {{ $s['configured'] ? 'false' : 'true' }} }">
            <div class="flex items-start gap-4">
                <div class="w-12 h-12 bg-gray-100 dark:bg-stone-800 rounded-2xl flex items-center justify-center flex-shrink-0">
                    <i class="{{ $service->icon }} text-gray-500 text-xl"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">{{ $service->label }}</h2>
                        @if($s['configured'])
                            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>Set
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-500">
                                <span class="w-2 h-2 rounded-full bg-gray-400"></span>Not set
                            </span>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $service->purpose }}</p>
                </div>
            </div>

            @if(!$s['configured'])
                <x-alert type="warning" class="mt-4">
                    Missing: {{ implode(', ', $s['missing']) }}. Follow the steps below to get {{ count($s['missing']) === 1 ? 'it' : 'them' }}.
                </x-alert>
            @endif

            <form wire:submit="save('{{ $id }}')" class="mt-5 space-y-4">
                @foreach($s['fields'] as $row)
                    @php($f = $row['field'])
                    <div>
                        <label for="{{ $id }}-{{ $f->name }}" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
                            {{ $f->label }}@if($f->required)<span class="text-red-500"> *</span>@endif
                            @if($row['set'])
                                <span class="ml-2 font-normal text-emerald-600 dark:text-emerald-400">
                                    set{{ $row['mask'] ? ' ('.$row['mask'].')' : '' }}{{ $row['origin'] === 'env' ? ' · from .env' : '' }}
                                </span>
                            @endif
                        </label>
                        <x-text-input id="{{ $id }}-{{ $f->name }}" class="w-full"
                                      :type="$f->secret ? 'password' : ($f->type === 'url' ? 'url' : 'text')"
                                      wire:model="inputs.{{ $id }}.{{ $f->name }}" autocomplete="off"
                                      :invalid="$errors->has('inputs.'.$id.'.'.$f->name)"
                                      placeholder="{{ $f->secret && $row['set'] ? 'Leave empty to keep' : $f->label }}" />
                        @if($f->hint)<p class="text-xs text-gray-400 dark:text-gray-600 mt-1">{{ $f->hint }}</p>@endif
                        @error('inputs.'.$id.'.'.$f->name)<p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>@enderror
                    </div>
                @endforeach

                <details class="text-xs text-gray-500 dark:text-gray-400">
                    <summary class="cursor-pointer select-none">Advanced: API address</summary>
                    <x-text-input class="w-full mt-2" type="url" wire:model="baseUrls.{{ $id }}" placeholder="{{ $service->baseUrl }}" autocomplete="off"
                                  :invalid="$errors->has('baseUrls.'.$id)" />
                    <p class="mt-1 text-gray-400 dark:text-gray-600">In use: {{ $s['baseUrl'] }}. Leave empty for the service's own address.</p>
                    @error('baseUrls.'.$id)<p class="text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>@enderror
                </details>

                <div class="flex flex-wrap items-center gap-3">
                    <x-primary-button size="md" type="submit" wire:loading.attr="disabled" wire:target="save">Save</x-primary-button>
                    <x-secondary-button size="md" type="button" wire:click="test('{{ $id }}')" wire:loading.attr="disabled" wire:target="test">
                        <span wire:loading.remove wire:target="test">Test</span>
                        <span wire:loading wire:target="test"><i class="fa-solid fa-spinner fa-spin"></i> Testing…</span>
                    </x-secondary-button>
                    @if($saved === $id)<span class="text-xs text-emerald-600 dark:text-emerald-400">Saved</span>@endif
                </div>
            </form>

            @foreach($s['results'] as $result)
                <p class="mt-3 flex items-start gap-2 text-xs {{ $text[$result['status']] }}">
                    <span class="mt-1 w-2 h-2 rounded-full flex-shrink-0 {{ $dot[$result['status']] }}"></span>
                    <span><span class="font-medium">{{ $result['name'] }}:</span> {{ $result['message'] }}</span>
                </p>
            @endforeach

            @if($service->account)
                <div class="mt-4 flex items-center justify-between gap-3 rounded-xl bg-gray-50 dark:bg-stone-800/60 px-4 py-3">
                    <span class="text-sm text-gray-900 dark:text-gray-100">
                        {{ $service->account['label'] }}:
                        <span class="{{ $s['connected'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500' }}">{{ $s['connected'] ? 'connected' : 'not connected' }}</span>
                    </span>
                    <x-secondary-button size="sm" href="{{ route($service->account['route']) }}">{{ $s['connected'] ? 'Manage' : 'Connect' }}</x-secondary-button>
                </div>
            @endif

            <div class="mt-5 border-t border-gray-100 dark:border-stone-800 pt-4">
                <button type="button" class="text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100" x-on:click="help = !help">
                    <i class="fa-solid fa-chevron-right w-3 transition-transform" x-bind:class="help && 'rotate-90'"></i> How to get {{ $service->label === 'TheAudioDB' ? 'a key' : 'the keys' }}
                </button>
                <div x-show="help" x-cloak class="mt-3 text-xs text-gray-500 dark:text-gray-400 space-y-3">
                    <ol class="list-decimal pl-5 space-y-1">
                        @foreach($service->steps as $step)<li>{{ $step }}</li>@endforeach
                    </ol>
                    @if($s['callback'])
                        <p>Redirect/callback URL: <span class="break-all select-all text-gray-700 dark:text-gray-300">{{ $s['callback'] }}</span></p>
                    @endif
                    <p><a href="{{ $service->link['url'] }}" target="_blank" rel="noopener" class="underline">{{ $service->link['label'] }}</a></p>
                    @if($service->terms)<p class="text-gray-400 dark:text-gray-600">{{ $service->terms }}</p>@endif
                </div>
            </div>
        </x-card>
    @endforeach
</div>
