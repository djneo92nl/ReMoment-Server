<form wire:submit="save" class="space-y-6">
    <x-card compact class="p-6 space-y-5">
        <div>
            <x-input-label value="Name" class="mb-1.5" />
            <x-text-input wire:model="name" type="text" placeholder="e.g. Evening" :invalid="$errors->has('name')" class="w-full max-w-sm" />
            <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
        </div>

        <div class="flex flex-wrap gap-6">
            <div>
                <x-input-label value="Icon" class="mb-1.5" />
                <x-select wire:model="icon">
                    <option value="">Default</option>
                    @foreach($icons as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select>
            </div>
            <div>
                <x-input-label value="Colour" class="mb-1.5" />
                <x-select wire:model="color">
                    <option value="">None</option>
                    @foreach($colors as $hex => $label)
                        <option value="{{ $hex }}">{{ ucfirst($label) }}</option>
                    @endforeach
                </x-select>
            </div>
        </div>

        <label class="flex items-center gap-3 cursor-pointer">
            <input type="checkbox" wire:model="stopOnError"
                   class="w-4 h-4 rounded border-gray-300 dark:border-stone-600 text-gray-900 dark:text-gray-100 focus:ring-gray-400 dark:focus:ring-stone-500">
            <span class="text-sm text-gray-700 dark:text-gray-300">Stop at the first step that fails</span>
        </label>
    </x-card>

    {{-- Steps --}}
    <div class="space-y-3">
        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Steps</h3>

        @if($formError)
            <x-alert type="error">{{ $formError }}</x-alert>
        @endif

        @forelse($steps as $i => $step)
            @php
                $op = ($step['kind'] ?? '') === 'operation' ? $operations->get($step['operation'] ?? '') : null;
            @endphp
            <x-card compact class="p-5 space-y-4" wire:key="step-{{ $i }}">
                <div class="flex items-center gap-3">
                    <span class="text-sm tabular-nums text-gray-400 dark:text-gray-600 w-5">{{ $i + 1 }}</span>

                    @if($step['kind'] === 'delay')
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Wait</span>
                        <x-text-input wire:model="steps.{{ $i }}.seconds" type="number" min="1" max="60" size="sm" class="w-20" aria-label="Seconds" />
                        <span class="text-sm text-gray-500">seconds</span>
                    @else
                        <x-select wire:model.live="steps.{{ $i }}.operation" class="flex-1 min-w-0" aria-label="Action">
                            <option value="">Choose an action…</option>
                            @foreach($operations as $id => $operation)
                                <option value="{{ $id }}">{{ $operation['label'] }}</option>
                            @endforeach
                        </x-select>
                    @endif

                    <div class="flex items-center gap-1 ml-auto flex-shrink-0">
                        <button type="button" wire:click="moveStep({{ $i }}, -1)" @disabled($i === 0) aria-label="Move up"
                                class="p-2 rounded-xl text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-stone-800 transition-colors disabled:opacity-25"><i class="fa-solid fa-arrow-up text-sm"></i></button>
                        <button type="button" wire:click="moveStep({{ $i }}, 1)" @disabled($i === count($steps) - 1) aria-label="Move down"
                                class="p-2 rounded-xl text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-stone-800 transition-colors disabled:opacity-25"><i class="fa-solid fa-arrow-down text-sm"></i></button>
                        <button type="button" wire:click="removeStep({{ $i }})" aria-label="Remove step"
                                class="p-2 rounded-xl text-gray-400 dark:text-gray-600 hover:text-red-500 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"><i class="fa-solid fa-trash-can text-sm"></i></button>
                    </div>
                </div>

                @if($op)
                    <div class="grid gap-4 sm:grid-cols-2">
                        @if($op['device_param'] !== null)
                            <div>
                                <x-input-label value="Device" class="mb-1.5" />
                                <x-select wire:model="steps.{{ $i }}.device_id" class="w-full">
                                    <option value="">Choose a device…</option>
                                    @foreach($devices as $device)
                                        <option value="{{ $device->id }}">{{ $device->device_name }}</option>
                                    @endforeach
                                </x-select>
                            </div>
                        @endif

                        @foreach($op['inputs'] as $name => $input)
                            @continue($name === $op['device_param'])
                            <div>
                                <x-input-label :value="\Illuminate\Support\Str::headline($name).($input['required'] ? '' : ' (optional)')" class="mb-1.5" />
                                @php
                                    $schema = $input['schema'];
                                    $list = isset(\App\Livewire\SceneEditor::PICKERS[$name]) ? $pickLists[\App\Livewire\SceneEditor::PICKERS[$name]] : null;
                                @endphp
                                @if($list !== null)
                                    @php
                                        $current = (string) ($step['params'][$name] ?? '');
                                    @endphp
                                    <x-select wire:model="steps.{{ $i }}.params.{{ $name }}" class="w-full">
                                        <option value="">{{ $input['required'] ? 'Choose…' : '—' }}</option>
                                        @if($current !== '' && !isset($list[(int) $current]))
                                            <option value="{{ $current }}">Missing (#{{ $current }})</option>
                                        @endif
                                        @foreach($list as $id => $label)
                                            <option value="{{ $id }}">{{ $label }}</option>
                                        @endforeach
                                    </x-select>
                                @elseif($searchPickers && isset(\App\Livewire\SceneEditor::SEARCH_PICKERS[$name]))
                                    @php
                                        $kind = \App\Livewire\SceneEditor::SEARCH_PICKERS[$name];
                                        $current = (string) ($step['params'][$name] ?? '');
                                        $found = $this->pickSearch($kind, $pickerSearch[$i][$name] ?? '');
                                        $currentLabel = ctype_digit($current) ? $this->pickLabel($kind, (int) $current) : null;
                                    @endphp
                                    <x-text-input wire:model.live.debounce.300ms="pickerSearch.{{ $i }}.{{ $name }}" type="search" size="sm" class="w-full mb-2"
                                                  :placeholder="'Search '.$kind.'s by name…'" />
                                    <x-select wire:model="steps.{{ $i }}.params.{{ $name }}" class="w-full">
                                        <option value="">{{ $input['required'] ? 'Choose…' : '—' }}</option>
                                        @if($current !== '' && !isset($found[(int) $current]))
                                            <option value="{{ $current }}">{{ $currentLabel ?? 'Missing (#'.$current.')' }}</option>
                                        @endif
                                        @foreach($found as $id => $label)
                                            <option value="{{ $id }}">{{ $label }}</option>
                                        @endforeach
                                    </x-select>
                                @elseif(isset($schema['enum']))
                                    <x-select wire:model="steps.{{ $i }}.params.{{ $name }}" class="w-full">
                                        <option value="">{{ $input['required'] ? 'Choose…' : '—' }}</option>
                                        @foreach($schema['enum'] as $option)
                                            <option value="{{ $option }}">{{ $option }}</option>
                                        @endforeach
                                    </x-select>
                                @elseif(($schema['type'] ?? '') === 'boolean')
                                    <x-select wire:model="steps.{{ $i }}.params.{{ $name }}" class="w-full">
                                        <option value="">{{ $input['required'] ? 'Choose…' : '—' }}</option>
                                        <option value="true">Yes</option>
                                        <option value="false">No</option>
                                    </x-select>
                                @else
                                    <x-text-input wire:model="steps.{{ $i }}.params.{{ $name }}" type="text" class="w-full"
                                                  :placeholder="in_array($schema['type'] ?? '', ['array', 'object'], true) ? 'JSON' : ''" />
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @foreach($stepErrors[$i] ?? [] as $message)
                    <x-input-error :messages="[$message]" />
                @endforeach
            </x-card>
        @empty
            <p class="text-sm text-gray-400 dark:text-gray-600">No steps yet.</p>
        @endforelse

        <div class="flex flex-wrap gap-2">
            <x-secondary-button type="button" wire:click="addOperation"><i class="fa-solid fa-plus text-xs"></i>Add action</x-secondary-button>
            <x-secondary-button type="button" wire:click="addDelay"><i class="fa-solid fa-hourglass-half text-xs"></i>Add wait</x-secondary-button>
        </div>
    </div>

    {{-- Schedule --}}
    <x-card compact class="p-6 space-y-4">
        <label class="flex items-center gap-3 cursor-pointer">
            <input type="checkbox" wire:model.live="scheduleEnabled"
                   class="w-4 h-4 rounded border-gray-300 dark:border-stone-600 text-gray-900 dark:text-gray-100 focus:ring-gray-400 dark:focus:ring-stone-500">
            <span class="text-sm text-gray-700 dark:text-gray-300">Run on a schedule</span>
        </label>

        @if($scheduleEnabled)
            <div>
                <x-input-label value="Time (server time)" class="mb-1.5" />
                <x-text-input wire:model="scheduleTime" type="time" :invalid="$errors->has('scheduleTime')" class="w-40" />
                <x-input-error :messages="$errors->get('scheduleTime')" class="mt-1.5" />
            </div>
            <div>
                <x-input-label value="Days (none checked: every day)" class="mb-2" />
                <div class="flex flex-wrap gap-4">
                    @foreach(\App\Livewire\SceneEditor::DAYS as $day => $label)
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="scheduleDays" value="{{ $day }}"
                                   class="w-4 h-4 rounded border-gray-300 dark:border-stone-600 text-gray-900 dark:text-gray-100 focus:ring-gray-400 dark:focus:ring-stone-500">
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endif
    </x-card>

    {{-- Triggers --}}
    <div class="space-y-3">
        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Triggers</h3>
        <p class="text-sm text-gray-500">Start this scene by itself: when an MQTT message arrives, or when a device changes state or source. The scene's own actions never start it again, and a trigger that fires is ignored for the pause below.</p>

        @foreach($triggers as $i => $trigger)
            <x-card compact class="p-5 space-y-4" wire:key="trigger-{{ $i }}">
                <div class="flex items-center gap-3">
                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $trigger['type'] === 'mqtt' ? 'MQTT message' : 'Device event' }}</span>
                    <button type="button" wire:click="removeTrigger({{ $i }})" aria-label="Remove trigger"
                            class="ml-auto p-2 rounded-xl text-gray-400 dark:text-gray-600 hover:text-red-500 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"><i class="fa-solid fa-trash-can text-sm"></i></button>
                </div>

                @if($trigger['type'] === 'mqtt')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label value="Topic filter" class="mb-1.5" />
                            <x-text-input wire:model="triggers.{{ $i }}.topic" type="text" class="w-full" placeholder="home/doorbell/#" />
                        </div>
                        <div>
                            <x-input-label value="Payload (optional)" class="mb-1.5" />
                            <x-text-input wire:model="triggers.{{ $i }}.payload" type="text" class="w-full" placeholder="Any message" />
                        </div>
                    </div>
                @else
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <x-input-label value="Device" class="mb-1.5" />
                            <x-select wire:model="triggers.{{ $i }}.device_id" class="w-full">
                                <option value="">Choose a device…</option>
                                @foreach($devices as $device)
                                    <option value="{{ $device->id }}">{{ $device->device_name }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div>
                            <x-input-label value="When" class="mb-1.5" />
                            <x-select wire:model.live="triggers.{{ $i }}.event" class="w-full">
                                <option value="state">its state becomes</option>
                                <option value="source">its source changes</option>
                            </x-select>
                        </div>
                        <div>
                            @if(($trigger['event'] ?? 'state') === 'state')
                                <x-input-label value="State" class="mb-1.5" />
                                <x-select wire:model="triggers.{{ $i }}.value" class="w-full">
                                    @foreach($states as $state)
                                        <option value="{{ $state }}">{{ $state }}</option>
                                    @endforeach
                                </x-select>
                            @else
                                <x-input-label value="Source (optional)" class="mb-1.5" />
                                <x-text-input wire:model="triggers.{{ $i }}.value" type="text" class="w-full" placeholder="Any source, or a name like HDMI 2" />
                            @endif
                        </div>
                    </div>
                @endif

                @foreach($triggerErrors[$i] ?? [] as $message)
                    <x-input-error :messages="[$message]" />
                @endforeach
            </x-card>
        @endforeach

        <div class="flex flex-wrap items-center gap-2">
            <x-secondary-button type="button" wire:click="addTrigger('mqtt')"><i class="fa-solid fa-plus text-xs"></i>Add MQTT trigger</x-secondary-button>
            <x-secondary-button type="button" wire:click="addTrigger('device')"><i class="fa-solid fa-plus text-xs"></i>Add device trigger</x-secondary-button>
        </div>

        @if($triggers !== [])
            <div>
                <x-input-label value="Pause after a start (seconds)" class="mb-1.5" />
                <x-text-input wire:model="triggerDebounce" type="number" min="5" max="3600" :invalid="$errors->has('triggerDebounce')" class="w-28" />
                <x-input-error :messages="$errors->get('triggerDebounce')" class="mt-1.5" />
            </div>
        @endif
    </div>

    {{-- Alarm --}}
    <x-card compact class="p-6 space-y-4">
        <label class="flex items-center gap-3 cursor-pointer">
            <input type="checkbox" wire:model.live="alarmEnabled"
                   class="w-4 h-4 rounded border-gray-300 dark:border-stone-600 text-gray-900 dark:text-gray-100 focus:ring-gray-400 dark:focus:ring-stone-500">
            <span class="text-sm text-gray-700 dark:text-gray-300">Alarm: raise the volume slowly after the steps</span>
        </label>

        @if($alarmEnabled)
            <p class="text-sm text-gray-500">The volume is set to the start level, the steps run (switch on, play a playlist or station), and then it rises to the target. The rise stops if someone changes the volume by hand or the device goes to standby.</p>
            <div class="grid gap-4 sm:grid-cols-4">
                <div class="sm:col-span-2">
                    <x-input-label value="Device" class="mb-1.5" />
                    <x-select wire:model="alarmDeviceId" class="w-full">
                        <option value="">Choose a device…</option>
                        @foreach($devices as $device)
                            <option value="{{ $device->id }}">{{ $device->device_name }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <x-input-label value="Rise over (minutes)" class="mb-1.5" />
                    <x-text-input wire:model="alarmMinutes" type="number" min="1" max="60" class="w-full" />
                </div>
                <div class="flex gap-3">
                    <div class="flex-1">
                        <x-input-label value="From" class="mb-1.5" />
                        <x-text-input wire:model="alarmStart" type="number" min="0" max="100" class="w-full" />
                    </div>
                    <div class="flex-1">
                        <x-input-label value="To" class="mb-1.5" />
                        <x-text-input wire:model="alarmTarget" type="number" min="1" max="100" class="w-full" />
                    </div>
                </div>
            </div>
            @foreach($alarmErrors as $message)
                <x-input-error :messages="[$message]" />
            @endforeach
        @endif
    </x-card>

    <div class="flex items-center gap-3">
        <x-primary-button size="lg" type="submit">Save scene</x-primary-button>
        <a href="{{ route('scenes.index') }}" class="px-5 py-2.5 rounded-xl text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">Cancel</a>
    </div>
</form>
