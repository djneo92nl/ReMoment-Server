@props(['analysis' => null, 'hash' => null])
{{-- One dot per analysis stage of a file: done (green), waiting (grey), failed (amber, will retry), gave up (red); stages this install cannot run are left out. The title lists each stage. --}}
@php
    $stages = collect(\App\Domain\Analysis\AnalysisProgress::forFile($analysis, $hash))->filter(fn ($s) => $s['enabled'] || $s['state'] === 'done');
    $dot = ['done' => 'bg-emerald-500', 'pending' => 'bg-gray-300 dark:bg-stone-600', 'failed' => 'bg-amber-400', 'gave_up' => 'bg-red-500'];
    $label = ['done' => 'done', 'pending' => 'waiting', 'failed' => 'failed, will retry', 'gave_up' => 'gave up'];
    $title = $stages->map(fn ($s, $key) => ucfirst($key).': '.$label[$s['state']].($s['message'] ? ' ('.$s['message'].')' : ''))->implode("\n");
@endphp
@if($stages->isNotEmpty())
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 px-1']) }} title="{{ $title }}" role="img" aria-label="Analysis: {{ $stages->where('state', 'done')->count() }} of {{ $stages->count() }} stages done">
        @foreach($stages as $key => $s)
            <span class="w-1.5 h-1.5 rounded-full {{ $dot[$s['state']] }}"></span>
        @endforeach
    </span>
@endif
