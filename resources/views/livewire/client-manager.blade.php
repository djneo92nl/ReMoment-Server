<div class="space-y-6">

    @if(session('success'))
        <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-2xl px-6 py-4 text-sm text-emerald-800 dark:text-emerald-300">
            <i class="fa-solid fa-circle-check mr-2"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Pending registrations --}}
    @php($pending = $clients->where('status', 'pending'))
    @if($pending->isNotEmpty())
        <div class="bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800/50 rounded-3xl overflow-hidden">
            <div class="px-8 py-5 border-b border-amber-200 dark:border-amber-800/50 flex items-center gap-3">
                <i class="fa-solid fa-clock text-amber-500"></i>
                <h2 class="text-base font-medium text-amber-900 dark:text-amber-300">
                    Pending Registrations
                    <span class="ml-2 text-xs font-normal bg-amber-200 dark:bg-amber-800/60 text-amber-800 dark:text-amber-300 px-2 py-0.5 rounded-full">{{ $pending->count() }}</span>
                </h2>
            </div>

            <div class="divide-y divide-amber-100 dark:divide-amber-900/30">
                @foreach($pending as $client)
                    <div class="px-8 py-6">
                        <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                            <div>
                                <div class="flex items-center gap-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                                    <span class="font-mono">{{ $client->ip_address ?? 'unknown IP' }}</span>
                                    @if($client->hardware_id)
                                        <span class="text-xs text-gray-400 dark:text-gray-600 font-mono">{{ $client->hardware_id }}</span>
                                    @endif
                                </div>
                                <div class="mt-1 flex flex-wrap gap-3 text-xs text-gray-500 dark:text-gray-500">
                                    @if($client->firmware_version)
                                        <span>fw {{ $client->firmware_version }}</span>
                                    @endif
                                    @if($client->build_number)
                                        <span>build {{ $client->build_number }}</span>
                                    @endif
                                    @if($client->metadata)
                                        @foreach($client->metadata as $k => $v)
                                            <span>{{ $k }}: {{ is_scalar($v) ? $v : json_encode($v) }}</span>
                                        @endforeach
                                    @endif
                                    <span class="text-gray-400 dark:text-gray-600">registered {{ $client->created_at->diffForHumans() }}</span>
                                </div>
                            </div>

                            @if($approvingId !== $client->id)
                                <div class="flex items-center gap-2">
                                    <button wire:click="startApprove({{ $client->id }})"
                                            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-xl transition-colors">
                                        Approve
                                    </button>
                                    <button wire:click="reject({{ $client->id }})"
                                            wire:confirm="Reject and remove this registration?"
                                            class="px-4 py-2 text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-xl transition-colors">
                                        Reject
                                    </button>
                                </div>
                            @endif
                        </div>

                        @if($approvingId === $client->id)
                            <div class="bg-white dark:bg-stone-900 rounded-2xl border border-gray-200 dark:border-stone-700 p-5 space-y-4">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-500 mb-1.5">Name <span class="font-normal">(optional)</span></label>
                                        <input type="text" wire:model="approveName" placeholder="e.g. Kitchen Controller"
                                               class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-stone-800 border border-gray-200 dark:border-stone-700 rounded-xl text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-500 mb-1.5">Client Type</label>
                                        <select wire:model="approveType"
                                                class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-stone-800 border border-gray-200 dark:border-stone-700 rounded-xl text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                            <option value="single">Single device (ESP8266 / simple)</option>
                                            <option value="multi">Multi device (ESP32-S3 / Pi / software)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 pt-1">
                                    <button wire:click="approve"
                                            class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-xl transition-colors">
                                        Approve &amp; Issue Token
                                    </button>
                                    <button wire:click="$set('approvingId', null)"
                                            class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition-colors">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Approved clients --}}
    @php($approved = $clients->where('status', 'approved'))
    <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden">
        <div class="px-8 py-6 border-b border-gray-100 dark:border-stone-800">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Registered Clients</h2>
            <p class="text-sm text-gray-500 dark:text-gray-500 mt-0.5">{{ $approved->count() }} {{ $approved->count() === 1 ? 'client' : 'clients' }} approved</p>
        </div>

        @if($approved->isEmpty())
            <div class="px-8 py-10 text-sm text-gray-400 dark:text-gray-600 text-center">
                No clients approved yet. Approve a pending registration to get started.
            </div>
        @else
            <div class="divide-y divide-gray-50 dark:divide-stone-800/50">
                @foreach($approved as $client)
                    <div class="px-8 py-5">
                        @if($editing !== $client->id)
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2.5 flex-wrap">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-xs font-medium
                                            {{ $client->type === 'single' ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400' : 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-700 dark:text-indigo-400' }}">
                                            <i class="fa-solid {{ $client->type === 'single' ? 'fa-microchip' : 'fa-layer-group' }} text-[10px]"></i>
                                            {{ $client->type }}
                                        </span>
                                        <span class="text-base font-medium text-gray-900 dark:text-gray-100">
                                            {{ $client->name ?? "Client #{$client->id}" }}
                                        </span>
                                    </div>

                                    <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-500">
                                        @if($client->ip_address)
                                            <span class="font-mono">{{ $client->ip_address }}</span>
                                        @endif
                                        @if($client->firmware_version)
                                            <span>fw {{ $client->firmware_version }}</span>
                                        @endif
                                        @if($client->build_number)
                                            <span>build {{ $client->build_number }}</span>
                                        @endif
                                        @if($client->last_seen_at)
                                            <span>seen {{ $client->last_seen_at->diffForHumans() }}</span>
                                        @else
                                            <span class="text-gray-300 dark:text-gray-700">never seen</span>
                                        @endif
                                    </div>

                                    <div class="mt-2 flex flex-wrap gap-2 items-center">
                                        @if($client->devices->isNotEmpty())
                                            @foreach($client->devices as $device)
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-100 dark:bg-stone-800 rounded-lg text-xs text-gray-600 dark:text-gray-400">
                                                    <i class="fa-solid fa-tv text-[9px] opacity-60"></i>
                                                    {{ $device->device_name }}
                                                </span>
                                            @endforeach
                                        @elseif($client->type === 'multi')
                                            <span class="text-xs text-gray-400 dark:text-gray-600 italic">All devices</span>
                                        @else
                                            <span class="text-xs text-amber-600 dark:text-amber-400 italic">No device assigned</span>
                                        @endif
                                    </div>

                                    <div class="mt-2.5 flex items-center gap-2" x-data="{ copied: false }">
                                        <span class="font-mono text-xs text-gray-400 dark:text-gray-600 truncate max-w-xs">
                                            {{ substr($client->api_token, 0, 12) }}…
                                        </span>
                                        <button @click="navigator.clipboard.writeText('{{ $client->api_token }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                                class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                            <span x-show="!copied"><i class="fa-regular fa-copy"></i></span>
                                            <span x-show="copied" class="text-emerald-500"><i class="fa-solid fa-check"></i></span>
                                        </button>
                                    </div>
                                </div>

                                <div class="flex items-center gap-1 shrink-0">
                                    <button wire:click="startEdit({{ $client->id }})"
                                            class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-stone-800 rounded-xl transition-colors">
                                        <i class="fa-solid fa-pen"></i> Edit
                                    </button>
                                    <button wire:click="regenerateToken({{ $client->id }})"
                                            wire:confirm="Regenerate the API token? The old token will stop working immediately."
                                            class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-amber-600 dark:hover:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/20 rounded-xl transition-colors">
                                        <i class="fa-solid fa-rotate"></i>
                                    </button>
                                    <button wire:click="delete({{ $client->id }})"
                                            wire:confirm="Remove {{ addslashes($client->name ?? 'this client') }}?"
                                            class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-xl transition-colors">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </div>
                            </div>

                        @else
                            {{-- Inline edit form --}}
                            <div class="space-y-5">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-500 mb-1.5">Name</label>
                                        <input type="text" wire:model="editName" placeholder="e.g. Kitchen Controller"
                                               class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-stone-800 border border-gray-200 dark:border-stone-700 rounded-xl text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-500 mb-1.5">Type</label>
                                        <select wire:model.live="editType"
                                                class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-stone-800 border border-gray-200 dark:border-stone-700 rounded-xl text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                            <option value="single">Single device</option>
                                            <option value="multi">Multi device</option>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-500 mb-2">
                                        Assigned Devices
                                        @if($editType === 'single')
                                            <span class="font-normal text-gray-400 dark:text-gray-600">(pick one)</span>
                                        @elseif($allDevices->isNotEmpty())
                                            <span class="font-normal text-gray-400 dark:text-gray-600">(leave empty to allow all)</span>
                                        @endif
                                    </label>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                        @foreach($allDevices as $device)
                                            <label class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl border cursor-pointer transition-colors
                                                {{ in_array($device->id, $editDeviceIds) ? 'border-indigo-300 dark:border-indigo-700 bg-indigo-50/50 dark:bg-indigo-900/10' : 'border-gray-200 dark:border-stone-700 hover:border-gray-300 dark:hover:border-stone-600' }}">
                                                <input type="checkbox" wire:model.live="editDeviceIds"
                                                       value="{{ $device->id }}"
                                                       class="rounded border-gray-300 dark:border-stone-600 text-indigo-600 focus:ring-indigo-500" />
                                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ $device->device_name }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @if($editType === 'single' && count($editDeviceIds) > 1)
                                        <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">Only the first selected device will be saved for single-type clients.</p>
                                    @endif
                                </div>

                                <div class="flex items-center gap-3 pt-1">
                                    <button wire:click="saveEdit"
                                            class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl transition-colors">
                                        Save
                                    </button>
                                    <button wire:click="cancelEdit"
                                            class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition-colors">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- API reference --}}
    <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8">
        <h2 class="text-base font-medium text-gray-900 dark:text-gray-100 mb-4">Client API Reference</h2>
        <div class="space-y-3 text-xs font-mono text-gray-500 dark:text-gray-500">
            <div class="flex flex-wrap gap-2 items-baseline">
                <span class="text-emerald-600 dark:text-emerald-400 font-semibold">POST</span>
                <span class="text-gray-700 dark:text-gray-300">/api/clients/register</span>
                <span class="text-gray-400 dark:text-gray-600 font-sans">→ registration_token</span>
            </div>
            <div class="flex flex-wrap gap-2 items-baseline">
                <span class="text-blue-600 dark:text-blue-400 font-semibold">GET</span>
                <span class="text-gray-700 dark:text-gray-300">/api/clients/status/{registration_token}</span>
                <span class="text-gray-400 dark:text-gray-600 font-sans">→ pending | approved + api_token</span>
            </div>
            <div class="flex flex-wrap gap-2 items-baseline">
                <span class="text-blue-600 dark:text-blue-400 font-semibold">GET</span>
                <span class="text-gray-700 dark:text-gray-300">/api/clients/{api_token}/devices</span>
                <span class="text-gray-400 dark:text-gray-600 font-sans">→ assigned devices list</span>
            </div>
            <div class="flex flex-wrap gap-2 items-baseline">
                <span class="text-amber-600 dark:text-amber-400 font-semibold">PUT</span>
                <span class="text-gray-700 dark:text-gray-300">/api/clients/{api_token}/heartbeat</span>
                <span class="text-gray-400 dark:text-gray-600 font-sans">→ updates IP + firmware + last seen</span>
            </div>
        </div>
        <p class="mt-4 text-xs text-gray-400 dark:text-gray-600">
            Registration body: <span class="font-mono">hardware_id</span>, <span class="font-mono">firmware_version</span>, <span class="font-mono">build_number</span>, <span class="font-mono">metadata</span> (all optional).
            Use <span class="font-mono">hardware_id</span> (MAC / chip ID) for idempotent re-registration on reboot.
        </p>
    </div>

</div>
