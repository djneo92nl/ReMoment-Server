<div class="space-y-6" data-test="mqtt-console">
    <x-card class="p-6 sm:p-8 space-y-4">
        <x-text-input wire:model="topic" placeholder="Topic" class="w-full font-mono" data-test="mqtt-topic" />
        <textarea wire:model="payload" rows="3" placeholder="Payload (empty + retained clears a retained message)" class="w-full rounded-xl border border-gray-200 dark:border-stone-700 bg-white dark:bg-stone-800 px-4 py-2.5 text-sm font-mono text-gray-900 dark:text-gray-100"></textarea>
        <div class="flex flex-wrap items-center gap-4">
            <x-select wire:model="qos" size="sm"><option value="0">QoS 0</option><option value="1">QoS 1</option></x-select>
            <label class="inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400"><input type="checkbox" wire:model="retain" class="rounded"> Retain</label>
            <x-primary-button type="button" size="md" wire:click="publish" data-test="mqtt-publish">Publish</x-primary-button>
        </div>
        @if($message)<x-alert type="info">{{ $message }}</x-alert>@endif
    </x-card>

    @assets
    <script>
        // The live topic browser of /settings/developer: its own browser connection to the broker's WebSocket listener,
        // the topics kept as a tree (a topic with anything below it is a folder), the value at the right of each.
        window.mqttTree = function (configured, port) {
            return {
                url: configured || ((location.protocol === 'https:' ? 'wss' : 'ws') + '://' + location.hostname + ':' + (port || 9001)), status: 'disconnected', error: '', client: null,
                topics: {}, open: {}, shown: {}, filter: '', total: 0,

                init() { this.connect(); },
                destroy() { this.disconnect(); },

                connect() {
                    this.disconnect();
                    this.error = '';
                    if (!window.mqtt) {
                        this.status = 'waiting';
                        let tries = 0;
                        const wait = setInterval(() => {
                            if (window.mqtt) { clearInterval(wait); this.connect(); }
                            else if (++tries > 20) { clearInterval(wait); this.status = 'disconnected'; this.error = 'mqtt.js did not load (it comes from cdn.jsdelivr.net): check the connection or an ad blocker.'; }
                        }, 250);
                        return;
                    }
                    this.status = 'connecting';
                    const client = window.mqtt.connect(this.url, { clientId: 'remoment-dev-' + Math.random().toString(16).slice(2, 10), reconnectPeriod: 0, connectTimeout: 5000 });
                    this.client = client;
                    client.on('connect', () => { this.status = 'connected'; this.error = ''; client.subscribe('remoment/#'); });
                    client.on('close', () => { if (this.client === client) { this.status = 'disconnected'; this.error ||= 'Could not reach ' + this.url + '. Is the broker running with its WebSocket listener (port 9001), and is the address right?'; } });
                    client.on('error', (e) => { this.error = (e && e.message) ? e.message + ' (' + this.url + ')' : 'Connection error.'; });
                    client.on('message', (topic, payload, packet) => this.receive(topic, payload.toString(), packet.retain));
                },
                disconnect() {
                    if (this.client) { const c = this.client; this.client = null; c.end(true); }
                    this.status = 'disconnected';
                },

                receive(topic, text, retained) {
                    this.total++;
                    if (text === '') { delete this.topics[topic]; return; } // an empty payload clears a retained topic
                    this.topics[topic] = { text, retained, at: new Date().toLocaleTimeString(), n: (this.topics[topic]?.n || 0) + 1 };
                },

                clear() { this.topics = {}; this.shown = {}; this.total = 0; },
                expandAll() { this.rows(true).forEach(r => r.folder && (this.open[r.path] = true)); this.allPaths().forEach(p => this.open[p] = true); },
                collapseAll() { this.open = {}; },
                // A click opens a folder and/or unfolds the value of a topic right under its row.
                toggle(row) { if (row.folder) this.open[row.path] = !this.open[row.path]; if (row.msg) this.shown[row.path] = !this.shown[row.path]; },

                allPaths() {
                    const paths = new Set();
                    Object.keys(this.topics).forEach(t => { const p = t.split('/'); for (let i = 1; i < p.length; i++) paths.add(p.slice(0, i).join('/')); });
                    return paths;
                },

                // The visible rows, depth first: folders first, then topics, each sorted by name.
                rows(all = false) {
                    const root = { children: {} };
                    const needle = this.filter.trim().toLowerCase();
                    for (const [topic, msg] of Object.entries(this.topics)) {
                        if (needle && !topic.toLowerCase().includes(needle) && !msg.text.toLowerCase().includes(needle)) continue;
                        let node = root;
                        const parts = topic.split('/');
                        parts.forEach((name, i) => {
                            node = node.children[name] ??= { name, path: parts.slice(0, i + 1).join('/'), children: {}, topics: 0 };
                            node.topics++;
                        });
                        node.msg = msg;
                    }
                    const out = [];
                    const walk = (node, depth) => {
                        Object.values(node.children)
                            .sort((a, b) => (Object.keys(b.children).length > 0) - (Object.keys(a.children).length > 0) || a.name.localeCompare(b.name, undefined, { numeric: true }))
                            .forEach(c => {
                                const folder = Object.keys(c.children).length > 0;
                                out.push({ name: c.name, path: c.path, depth, folder, msg: c.msg, count: c.topics - (c.msg ? 1 : 0) });
                                if (folder && (all || needle || this.open[c.path])) walk(c, depth + 1);
                            });
                    };
                    walk(root, 0);
                    return out;
                },

                pretty(text) { try { return JSON.stringify(JSON.parse(text), null, 2); } catch (e) { return text; } },
            };
        };
    </script>
    @endassets

    {{-- The values go through data attributes: Blade does not compile @js() inside a component tag's attributes. --}}
    <x-card class="p-6 sm:p-8" wire:ignore data-url="{{ config('mqtt.ws_url') }}" data-port="{{ config('mqtt.public_ws_port') }}" x-data="mqttTree($el.dataset.url, $el.dataset.port)" data-test="mqtt-tree">
        <div class="flex flex-wrap items-center gap-2">
            <x-text-input size="sm" x-model="url" class="min-w-0 flex-1 font-mono" placeholder="ws://host:9001" data-test="mqtt-url" />
            <x-primary-button type="button" size="sm" @click="connect()" x-show="status !== 'connected'" data-test="mqtt-connect">Connect</x-primary-button>
            <x-secondary-button type="button" size="sm" @click="connect()" x-show="status === 'connected'">Reconnect</x-secondary-button>
            <x-secondary-button type="button" size="sm" @click="disconnect()" x-show="status === 'connected' || status === 'connecting'">Disconnect</x-secondary-button>
            <span class="inline-flex items-center gap-1.5 text-sm text-gray-500">
                <span class="h-2 w-2 rounded-full" :class="status === 'connected' ? 'bg-emerald-500' : (status === 'disconnected' ? 'bg-red-500' : 'bg-amber-500')"></span>
                <span x-text="status" data-test="mqtt-status"></span>
            </span>
        </div>
        <p x-show="error" x-text="error" class="mt-3 rounded-xl bg-red-50 px-4 py-2 text-xs text-red-700 dark:bg-red-900/20 dark:text-red-300" data-test="mqtt-error"></p>

        <div class="mt-5 flex flex-wrap items-center justify-between gap-2">
            <x-text-input size="sm" x-model="filter" placeholder="Filter topic or value" class="w-64" />
            <div class="flex items-center gap-2 text-xs text-gray-500">
                <span x-text="Object.keys(topics).length + ' topics · ' + total + ' messages'"></span>
                <x-secondary-button type="button" size="sm" @click="expandAll()">Expand</x-secondary-button>
                <x-secondary-button type="button" size="sm" @click="collapseAll()">Collapse</x-secondary-button>
                <x-secondary-button type="button" size="sm" @click="clear()">Clear</x-secondary-button>
            </div>
        </div>

        <div class="mt-3 max-h-[28rem] overflow-y-auto rounded-xl border border-gray-200 dark:border-stone-700 text-sm" data-test="mqtt-rows">
            <template x-for="(row, i) in rows()" :key="row.path">
                <div :class="i % 2 ? 'bg-gray-50/60 dark:bg-stone-900/40' : ''">
                <div @click="toggle(row)" class="flex cursor-pointer items-center gap-2 px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-stone-800">
                    <span class="flex min-w-0 items-center gap-2" :style="'padding-left:' + (row.depth * 1.25) + 'rem'">
                        <i class="fa-solid w-3 shrink-0 text-center text-[10px] text-gray-400" :class="row.folder ? (open[row.path] || filter ? 'fa-chevron-down' : 'fa-chevron-right') : (shown[row.path] ? 'fa-chevron-down' : 'fa-chevron-right')"></i>
                        <i class="fa-solid w-4 shrink-0 text-center" :class="row.folder ? 'fa-folder text-sky-400' : 'fa-file-lines text-gray-400'"></i>
                        <span class="truncate text-gray-900 dark:text-gray-100" x-text="row.name"></span>
                        <span x-show="row.folder" class="shrink-0 text-xs text-gray-400" x-text="row.count"></span>
                    </span>
                    <span class="ml-auto min-w-0 max-w-[55%] truncate text-right font-mono text-xs text-gray-500" x-show="row.msg" :title="row.msg && row.msg.text" x-text="row.msg && row.msg.text"></span>
                    <span x-show="row.msg && row.msg.retained" class="shrink-0 rounded-full bg-amber-100 px-1.5 text-[10px] text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">retained</span>
                    <span class="w-16 shrink-0 text-right text-[10px] tabular-nums text-gray-400" x-text="row.msg ? row.msg.at : ''"></span>
                </div>
                <div x-show="row.msg && shown[row.path]" class="mx-3 mb-2 rounded-xl bg-gray-100 p-3 dark:bg-stone-800" :style="'margin-left:' + (row.depth * 1.25 + 3.2) + 'rem'" data-test="mqtt-detail">
                    <div class="flex items-center justify-between gap-3">
                        <code class="break-all text-xs text-gray-700 dark:text-gray-300" x-text="row.path"></code>
                        <x-secondary-button type="button" size="sm" @click.stop="$wire.set('topic', row.path); $wire.set('payload', row.msg.text)">Use in publisher</x-secondary-button>
                    </div>
                    <pre class="mt-2 max-h-48 overflow-auto text-xs text-gray-800 dark:text-gray-200" x-text="row.msg && pretty(row.msg.text)"></pre>
                </div>
                </div>
            </template>
            <p x-show="rows().length === 0" class="p-4 text-gray-400" x-text="status === 'connected' ? 'Connected. Nothing received yet.' : 'Not connected.'"></p>
        </div>

    </x-card>
</div>
