<div class="space-y-4" data-test="export-from-library">
    <livewire:library-picker wire:model="picks" name="export" :own-files-only="true" confirm-label="Choose export settings" />

    @if($trackIds)
        <livewire:export-dialog type="tracks" :track-ids="$trackIds" :open="true" :key="'export-picked-'.$version" />
    @endif
</div>
