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
                            @php($schema = $input['schema'])
                            <div>
                                <x-input-label :value="\Illuminate\Support\Str::headline($name).($input['required'] ? '' : ' (optional)')" class="mb-1.5" />
                                @php($list = isset(\App\Livewire\SceneEditor::PICKERS[$name]) ? $pickLists[\App\Livewire\SceneEditor::PICKERS[$name]] : null)
                                @if($list !== null)
                                    @php($current = (string) ($step['params'][$name] ?? ''))
                                    <x-select wire:model="steps.{{ $i }}.params.{{ $name }}" class="w-full">
                                        <option value="">{{ $input['required'] ? 'Choose…' : '—' }}</option>
                                        @if($current !== '' && !isset($list[(int) $current]))
                                            <option value="{{ $current }}">Missing (#{{ $current }})</option>
                                        @endif
                                        @foreach($list as $id => $label)
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

    <div class="flex items-center gap-3">
        <x-primary-button size="lg" type="submit">Save scene</x-primary-button>
        <a href="{{ route('scenes.index') }}" class="px-5 py-2.5 rounded-xl text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">Cancel</a>
    </div>
</form>
