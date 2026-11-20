<?php

namespace Tests\Unit;

use App\Domain\SubDevices\InvalidDeviceLink;
use App\Domain\SubDevices\LinkMeta;
use PHPUnit\Framework\TestCase;

/** What a link's meta may hold. */
class LinkMetaTest extends TestCase
{
    public function test_nothing_set_is_null(): void
    {
        $this->assertNull(LinkMeta::normalize(null));
        $this->assertNull(LinkMeta::normalize([]));
        $this->assertNull(LinkMeta::normalize(['on_activate' => [], 'follow' => null, 'follow_off_after' => null]));
    }

    public function test_actions_are_a_list_and_a_single_action_is_a_list_of_one(): void
    {
        $this->assertSame(
            ['on_activate' => [['action' => 'power_on'], ['action' => 'source', 'source_id' => 'LINE IN']], 'on_deactivate' => [['action' => 'power_off']]],
            LinkMeta::normalize([
                'on_activate' => [['action' => 'power_on', 'ignored' => 1], ['action' => 'source', 'source_id' => ' LINE IN ']],
                'on_deactivate' => ['action' => 'power_off'],
            ]),
        );
    }

    public function test_a_bad_action_is_refused_with_a_reason(): void
    {
        $cases = [
            [['on_activate' => [['action' => 'explode']]], 'An action of on_activate'],
            [['on_activate' => [['action' => 'source']]], 'needs a source_id'],
            [['on_activate' => [['action' => 'source', 'source_id' => '  ']]], 'needs a source_id'],
            [['on_activate' => 'power_on'], 'list of actions'],
            [['on_activate' => array_fill(0, 6, ['action' => 'power_on'])], 'at most 5'],
            [['follow' => 'sometimes'], 'follow must be one of'],
            [['follow_off_after' => 0], 'between 1 and 1440'],
            [['follow_off_after' => 'soon'], 'whole number'],
            [['colour' => 'red'], "Unknown link setting 'colour'"],
        ];

        foreach ($cases as [$meta, $message]) {
            try {
                LinkMeta::normalize($meta);
                $this->fail('Refused: '.json_encode($meta));
            } catch (InvalidDeviceLink $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_the_follow_rule_has_a_default_and_a_valid_setting(): void
    {
        $this->assertSame('from_standby', LinkMeta::follow(null));
        $this->assertSame('from_standby', LinkMeta::follow([]));
        $this->assertSame(['follow' => 'always', 'follow_off_after' => 15], LinkMeta::normalize(['follow' => 'always', 'follow_off_after' => '15']));
        $this->assertSame('off', LinkMeta::follow(['follow' => 'off']));
    }

    public function test_an_edit_keeps_the_servers_state_and_cannot_set_it(): void
    {
        $current = ['follow' => 'off', 'state' => ['woke_host' => true]];

        $this->assertSame(['follow' => 'always', 'state' => ['woke_host' => true]], LinkMeta::replace(['follow' => 'always', 'state' => ['woke_host' => false]], $current));
        $this->assertSame(['state' => ['woke_host' => true]], LinkMeta::replace(null, $current));
        $this->assertNull(LinkMeta::replace(['state' => ['woke_host' => true]], null));
    }
}
