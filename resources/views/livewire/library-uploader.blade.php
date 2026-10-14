<div x-data="libraryDropzone({{ \Illuminate\Support\Js::from(['start' => route('settings.storage.uploads.start'), 'receive' => route('settings.storage.uploads.receive', ['id' => '__ID__'])]) }})"
     class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden"
     data-test="library-uploader" @if($polling) wire:poll.2s @endif>

    <div class="px-8 py-6 border-b border-gray-100 dark:border-stone-800">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Add music</h2>
        <p class="text-sm text-gray-500 dark:text-gray-500 mt-0.5">
            Drop audio files here. Each one is uploaded, its tags are read, it is copied into the library layout and its enrichment is queued.
        </p>

        <label class="mt-4 flex flex-col items-center justify-center gap-1 px-6 py-8 rounded-2xl border-2 border-dashed cursor-pointer text-sm transition-colors"
               :class="over ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-300' : 'border-gray-200 dark:border-stone-700 text-gray-500 dark:text-gray-400 hover:border-indigo-400'"
               x-on:dragover.prevent="over = true" x-on:dragleave.prevent="over = false" x-on:drop.prevent="over = false; add($event.dataTransfer.files)">
            <i class="fa-solid fa-cloud-arrow-up text-2xl"></i>
            <span>Drop files here or <span class="underline">choose files</span></span>
            <input type="file" multiple accept="audio/*,.flac,.m4a,.opus,.ogg,.oga,.wav,.aiff,.aif,.wma,.mp3" class="hidden" data-test="upload-input"
                   x-on:change="add($event.target.files); $event.target.value = ''">
        </label>
    </div>

    @if($rows !== [])
        @php
            $steps = [\App\Domain\Storage\LibraryUploads::UPLOADING => 'Upload', \App\Domain\Storage\LibraryUploads::PARSING => 'Parse', \App\Domain\Storage\LibraryUploads::COPYING => 'Copy', \App\Domain\Storage\LibraryUploads::ENRICHING => 'Enrich'];
        @endphp
        <div class="px-8 py-3 flex items-center justify-between text-xs text-gray-400 dark:text-gray-600 border-b border-gray-100 dark:border-stone-800">
            <span>{{ count($rows) }} {{ count($rows) === 1 ? 'file' : 'files' }}</span>
            @if($finished)<button wire:click="clear" class="hover:text-gray-600 dark:hover:text-gray-400">Clear finished</button>@endif
        </div>
        <div class="divide-y divide-gray-50 dark:divide-stone-800/50" data-test="upload-rows">
            @foreach($rows as $row)
                @php
                    $order = array_keys($steps);
                    $current = $row['state'] === \App\Domain\Storage\LibraryUploads::DONE ? count($order) : array_search($row['state'] === \App\Domain\Storage\LibraryUploads::FAILED ? ($row['failed_at'] ?? $order[0]) : $row['state'], $order);
                    $current = $current === false ? 0 : $current;
                @endphp
                <div class="px-8 py-4" wire:key="upload-{{ $row['id'] }}" data-test="upload-{{ $row['id'] }}">
                    <div class="flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-sm text-gray-900 dark:text-gray-100 truncate">{{ $row['title'] ?? $row['name'] }}@if(!empty($row['artist']))<span class="text-gray-400"> · {{ $row['artist'] }}</span>@endif</p>
                            <p class="text-xs text-gray-400 dark:text-gray-600 truncate">
                                {{ $row['name'] }} · {{ number_format($row['size'] / 1048576, 1) }} MB
                                @if(!empty($row['path'])) → {{ $row['path'] }} @endif
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3 text-xs">
                            @foreach($steps as $state => $label)
                                @php $i = array_search($state, $order); @endphp
                                @if($i < $current)
                                    <span class="text-emerald-500"><i class="fa-solid fa-circle-check mr-1"></i>{{ $label }}</span>
                                @elseif($i === $current && $row['state'] === \App\Domain\Storage\LibraryUploads::FAILED)
                                    <span class="text-red-500"><i class="fa-solid fa-circle-xmark mr-1"></i>{{ $label }}</span>
                                @elseif($i === $current)
                                    <span class="text-amber-500"><i class="fa-solid fa-spinner fa-spin mr-1"></i>{{ $label }}@if($state === \App\Domain\Storage\LibraryUploads::UPLOADING)<span x-text="progress['{{ $row['id'] }}'] !== undefined ? ' ' + progress['{{ $row['id'] }}'] + '%' : ''"></span>@endif</span>
                                @else
                                    <span class="text-gray-300 dark:text-stone-600"><i class="fa-regular fa-circle mr-1"></i>{{ $label }}</span>
                                @endif
                            @endforeach
                        </div>
                    </div>
                    @if(!empty($row['message']))
                        <p class="mt-1 text-xs {{ $row['state'] === \App\Domain\Storage\LibraryUploads::FAILED ? 'text-red-500' : 'text-gray-400 dark:text-gray-600' }}">{{ $row['message'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <script>
        window.libraryDropzone = window.libraryDropzone || function (urls) {
            return {
                over: false, progress: {}, pending: [], running: 0,
                add(files) { this.pending.push(...files); this.pump(); },
                pump() {
                    while (this.running < 2 && this.pending.length) {
                        this.running++;
                        this.send(this.pending.shift()).finally(() => { this.running--; this.pump(); });
                    }
                },
                async send(file) {
                    const token = document.querySelector('meta[name=csrf-token]').content;
                    let row;
                    try {
                        const res = await fetch(urls.start, {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                            body: JSON.stringify({ name: file.name, size: file.size }),
                        });
                        row = await res.json();
                    } catch (e) { return; }
                    this.$wire.$refresh();
                    if (!row.accepted) { return; }

                    await new Promise((resolve) => {
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', urls.receive.replace('__ID__', row.id));
                        xhr.setRequestHeader('X-CSRF-TOKEN', token);
                        xhr.setRequestHeader('Accept', 'application/json');
                        xhr.upload.onprogress = (e) => { if (e.lengthComputable) { this.progress[row.id] = Math.round(e.loaded / e.total * 100); } };
                        xhr.onloadend = () => {
                            if (xhr.status === 0) { this.$wire.uploadFailed(row.id); } else { this.$wire.$refresh(); }
                            resolve();
                        };
                        const form = new FormData();
                        form.append('file', file);
                        xhr.send(form);
                    });
                },
            };
        };
    </script>
</div>
