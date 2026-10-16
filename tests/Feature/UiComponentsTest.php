<?php

namespace Tests\Feature;

use App\Livewire\DeviceCacheCard;
use App\Livewire\DeviceQueue;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** The shared Blade components of DESIGN.md: what a page gets when it uses them. */
class UiComponentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_is_the_standard_surface_and_can_be_compact_or_a_link(): void
    {
        $this->assertStringContainsString('rounded-3xl', Blade::render('<x-card class="p-6">x</x-card>'));
        $this->assertStringContainsString('p-6', Blade::render('<x-card class="p-6">x</x-card>'));
        $this->assertStringContainsString('rounded-2xl', Blade::render('<x-card compact>x</x-card>'));
        $this->assertStringContainsString('overflow-hidden', Blade::render('<x-card flush>x</x-card>'));
        $this->assertStringContainsString('<a href="/a"', Blade::render('<x-card href="/a">x</x-card>'));
    }

    public function test_alert_types_get_their_colour_icon_and_role(): void
    {
        $error = Blade::render('<x-alert type="error">Nope</x-alert>');
        $this->assertStringContainsString('Nope', $error);
        $this->assertStringContainsString('bg-red-50', $error);
        $this->assertStringContainsString('fa-circle-exclamation', $error);
        $this->assertStringContainsString('role="alert"', $error);

        $this->assertStringContainsString('bg-emerald-50', Blade::render('<x-alert type="success">ok</x-alert>'));
        $this->assertStringContainsString('bg-sky-50', Blade::render('<x-alert type="bogus">?</x-alert>'));
        $this->assertStringContainsString('Retry', Blade::render('<x-alert type="warning">w<x-slot name="action">Retry</x-slot></x-alert>'));
        $this->assertSame('', trim(Blade::render('<x-alert type="success">{{ session("missing") }}</x-alert>')), 'an alert with no message renders nothing');
    }

    public function test_page_header_renders_title_subtitle_back_and_actions(): void
    {
        $html = Blade::render('<x-page-header title="MQTT" subtitle="Broker" back="/settings"><x-slot name="actions">Go</x-slot></x-page-header>');

        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('MQTT', $html);
        $this->assertStringContainsString('Broker', $html);
        $this->assertStringContainsString('href="/settings"', $html);
        $this->assertStringContainsString('Go', $html);

        $bare = Blade::render('<x-page-header title="Devices" />');
        $this->assertStringNotContainsString('fa-arrow-left', $bare);
    }

    public function test_badge_variants(): void
    {
        $this->assertStringContainsString('rounded-full', Blade::render('<x-badge>Rock</x-badge>'));
        $this->assertStringContainsString('border', Blade::render('<x-badge variant="outline">x</x-badge>'));
        $this->assertStringContainsString('bg-red-50', Blade::render('<x-badge variant="danger">x</x-badge>'));
    }

    public function test_empty_state_wraps_in_a_card_unless_bare(): void
    {
        $html = Blade::render('<x-empty-state icon="list-ul" title="No playlists" text="Create one">Add</x-empty-state>');
        $this->assertStringContainsString('rounded-3xl', $html);
        $this->assertStringContainsString('fa-list-ul', $html);
        $this->assertStringContainsString('No playlists', $html);
        $this->assertStringContainsString('Create one', $html);
        $this->assertStringContainsString('Add', $html);

        $this->assertStringNotContainsString('rounded-3xl', Blade::render('<x-empty-state bare title="Empty" />'));
    }

    public function test_table_renders_head_rows_and_responsive_columns(): void
    {
        $html = Blade::render('<x-table><x-slot name="head"><x-table.th>Name</x-table.th><x-table.th hide="md">IP</x-table.th></x-slot><tr><td>Kitchen</td></tr></x-table>');

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('Name', $html);
        $this->assertStringContainsString('hidden md:table-cell', $html);
        $this->assertStringContainsString('Kitchen', $html);
        $this->assertStringContainsString('overflow-hidden', $html);
    }

    /** Livewire puts wire:id on the first tag it finds: a card at the root of a component must be that tag, not an inner heading. */
    public function test_a_card_can_be_the_root_of_a_livewire_component(): void
    {
        $device = Device::factory()->create();

        foreach ([DeviceQueue::class, DeviceCacheCard::class] as $component) {
            $html = Livewire::test($component, ['device' => $device])->html();

            $this->assertMatchesRegularExpression('/<div [^>]*wire:id="[^"]+"[^>]*rounded-3xl/', $html, $component);
        }
    }

    /** A shared component opened but never closed (or closed with </div>) renders as broken nesting that no page test notices. */
    public function test_every_view_closes_the_components_it_opens(): void
    {
        $tags = ['x-card', 'x-table', 'x-empty-state', 'x-alert', 'x-page-header', 'x-badge', 'x-chip', 'x-shelf', 'x-media-card', 'x-primary-button', 'x-secondary-button', 'x-danger-button', 'x-select', 'x-tabs', 'x-tabs.panel', 'x-menu', 'x-menu.item', 'x-album-hero', 'x-album-waveform'];
        $problems = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = $file->getContents();

            foreach ($tags as $tag) {
                $opens = 0;
                preg_match_all('/<'.preg_quote($tag, '/').'(?=[\s>\/])/', $source, $matches, PREG_OFFSET_CAPTURE);

                foreach ($matches[0] as [$text, $offset]) {
                    $quote = null;
                    for ($i = $offset, $n = strlen($source); $i < $n; $i++) {
                        $c = $source[$i];
                        if ($quote !== null) {
                            $quote = $c === $quote ? null : $quote;
                        } elseif ($c === '"' || $c === "'") {
                            $quote = $c;
                        } elseif ($c === '>') {
                            break;
                        }
                    }
                    $opens += $source[$i - 1] === '/' ? 0 : 1;
                }

                $closes = substr_count($source, '</'.$tag.'>');
                if ($opens !== $closes) {
                    $problems[] = "{$file->getRelativePathname()}: <{$tag}> opened {$opens}x, closed {$closes}x";
                }
            }
        }

        $this->assertSame([], $problems);
    }

    /** Shared colours are tokens (tailwind.config.js) or CSS variables (app.css), not literals pasted into views. */
    public function test_views_hold_no_hex_colours_and_no_cdn_tailwind(): void
    {
        $problems = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = $file->getContents();

            if ($file->getFilename() !== 'receiver.blade.php' && preg_match_all('/#[0-9a-fA-F]{6}\b/', $source, $found)) {
                $problems[] = $file->getRelativePathname().': '.implode(' ', array_unique($found[0]));
            }
            if (str_contains($source, 'cdn.tailwindcss.com')) {
                $problems[] = $file->getRelativePathname().': loads Tailwind from the CDN';
            }
        }

        $this->assertSame([], $problems);
    }

    /** A flash alert must sit inside @if(session(...)), or an empty banner shows on every page load. */
    public function test_flash_alerts_are_guarded_by_their_session_key(): void
    {
        $problems = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            foreach (preg_split('/\R/', $file->getContents()) as $i => $line) {
                if (!preg_match("/<x-alert[^>]*>\\{\\{ session\\('(\\w+)'\\) \\}\\}/", $line, $m)) {
                    continue;
                }
                $before = implode("\n", array_slice(preg_split('/\R/', $file->getContents()), max(0, $i - 2), 2));
                if (!str_contains($before, "session('{$m[1]}')")) {
                    $problems[] = $file->getRelativePathname().':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $problems);
    }
}
