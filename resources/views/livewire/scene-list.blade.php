<div class="space-y-6">
    @if($feedback)
        <x-alert :type="$feedbackIsError ? 'error' : 'success'">{{ $feedback }}</x-alert>
    @endif

    @if($scenes->isEmpty())
        <x-empty-state icon="wand-magic-sparkles" text="No scenes yet. A scene sets volume, sources and playback in one go." />
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        @foreach($scenes as $scene)
            @php
                $icon = $scene->icon && preg_match('/^[a-z0-9-]+$/', $scene->icon) ? $scene->icon : 'wand-magic-sparkles';
                $tint = $scene->color && preg_match('/^#[0-9a-fA-F]{6}$/', $scene->color) ? $scene->color : null;
                $status = $scene->last_run_status;
            @endphp
            <x-card compact class="px-6 py-5 flex items-center gap-4" wire:key="scene-{{ $scene->id }}">
                <div class="w-10 h-10 rounded-xl bg-gray-100 dark:bg-stone-800 flex items-center justify-center flex-shrink-0 text-gray-500"
                     @if($tint) style="color: {{ $tint }}" @endif>
                    <i class="fa-solid fa-{{ $icon }}"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $scene->name }}</p>
                    <p class="text-sm text-gray-400 dark:text-gray-600 mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span>{{ trans_choice(':count step|:count steps', count($scene->steps ?? [])) }}</span>
                        @if($scene->schedule_enabled)
                            <span>· {{ $scene->schedule_time }}</span>
                        @endif
                        @if($status)
                            <x-badge :variant="match($status) { 'ok' => 'success', 'partial' => 'warning', 'running' => 'info', default => 'danger' }">{{ $status }}</x-badge>
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-1 flex-shrink-0">
                    <x-primary-button size="md" type="button" wire:click="run({{ $scene->id }})" wire:loading.attr="disabled">
                        <i class="fa-solid fa-play text-xs"></i>
                        Run
                    </x-primary-button>
                    <a href="{{ route('scenes.edit', $scene) }}" aria-label="Edit {{ $scene->name }}"
                       class="p-2 rounded-xl text-gray-400 dark:text-gray-600 hover:text-gray-700 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-stone-800 transition-colors">
                        <i class="fa-solid fa-pen text-sm"></i>
                    </a>
                    <button type="button" wire:click="delete({{ $scene->id }})" wire:confirm="Delete this scene?" aria-label="Delete {{ $scene->name }}"
                            class="p-2 rounded-xl text-gray-400 dark:text-gray-600 hover:text-red-500 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                        <i class="fa-solid fa-trash-can text-sm"></i>
                    </button>
                </div>
            </x-card>
        @endforeach
    </div>
</div>
