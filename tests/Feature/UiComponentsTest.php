<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** The shared Blade components of DESIGN.md: what a page gets when it uses them. */
class UiComponentsTest extends TestCase
{
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
}
