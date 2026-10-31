<div class="space-y-4" data-test="profile-picker">
    <div class="grid gap-4 md:grid-cols-3">
        @foreach($cards as $key => $card)
            <x-card class="flex flex-col p-5" data-test="profile-card-{{ $key }}" wire:key="profile-{{ $key }}">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="font-medium text-gray-900 dark:text-gray-100">{{ $card['label'] }}</h3>
                    <div class="flex flex-wrap justify-end gap-1.5">
                        @if($running === $key)
                            <x-badge variant="success">Running</x-badge>
                        @endif
                        @if($chosen === $key && $running !== $key)
                            <x-badge variant="info">Chosen</x-badge>
                        @endif
                    </div>
                </div>
                <p class="mt-0.5 text-sm text-gray-500">{{ $card['tagline'] }}</p>

                <dl class="mt-4 flex-1 space-y-3 text-sm">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Runs</dt>
                        <dd class="mt-0.5 text-gray-700 dark:text-gray-300">{{ $card['runs'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Suits</dt>
                        <dd class="mt-0.5 text-gray-700 dark:text-gray-300">{{ $card['hardware'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Needs</dt>
                        <dd class="mt-0.5 text-gray-700 dark:text-gray-300">{{ $card['needs'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Database</dt>
                        <dd class="mt-0.5 text-gray-700 dark:text-gray-300">{{ $card['database'] === 'sqlite' ? 'SQLite (a file in a named volume)' : 'MariaDB' }}</dd>
                    </div>
                </dl>

                @if($canChoose && $chosen !== $key && !($chosen === null && $running === $key))
                    <div class="mt-5">
                        <x-secondary-button size="md" type="button" wire:click="choose('{{ $key }}')">Choose {{ $card['label'] }}</x-secondary-button>
                    </div>
                @elseif($canChoose && $chosen === null && $running === $key)
                    <div class="mt-5">
                        <x-secondary-button size="md" type="button" wire:click="choose('{{ $key }}')">Keep {{ $card['label'] }}</x-secondary-button>
                    </div>
                @endif
            </x-card>
        @endforeach
    </div>

    @if($mismatch)
        <x-alert type="warning" data-test="profile-mismatch">
            <p>This server runs <strong>{{ $cards[$running]['label'] }}</strong>: the <code>PROFILE</code> in the environment wins over this choice, so nothing changes until you change it there.</p>
            @if($upgrade)
                <p class="mt-2">
                    To move to {{ $cards[$chosen]['label'] }}: set <code>PROFILE={{ $chosen }}</code> in <code>.env</code> (Ansible: <code>profile: {{ $chosen }}</code>),
                    @if($running === 'control')
                        run <code>php artisan profile:upgrade</code> first (it backs up and copies the SQLite data into MariaDB, and changes nothing else),
                        set <code>DB_CONNECTION=mariadb</code>,
                    @endif
                    @if($composeProfiles !== [])
                        add <code>COMPOSE_PROFILES={{ implode(',', $composeProfiles) }}</code> for the containers,
                    @endif
                    then restart the containers (<code>docker compose up -d</code>). Compose profiles start and stop containers, so a restart is needed.
                </p>
            @else
                <p class="mt-2">Moving down to {{ $cards[$chosen]['label'] }} is not supported: the library data would be dropped. Start a fresh install with that profile instead.</p>
            @endif
        </x-alert>
    @elseif($chosen !== null)
        <x-alert type="success" data-test="profile-match">This server runs {{ $cards[$running]['label'] }}, as chosen.</x-alert>
    @endif
</div>
