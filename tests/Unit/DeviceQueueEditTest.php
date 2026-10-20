<?php

namespace Tests\Unit;

use App\Integrations\Common\DeviceQueue;
use PHPUnit\Framework\TestCase;

class DeviceQueueEditTest extends TestCase
{
    /** ids 1..5, playing the second (id 2): up next is 3, 4, 5. */
    private function queue(): DeviceQueue
    {
        return new DeviceQueue([1, 2, 3, 4, 5], 1);
    }

    public function test_append_adds_after_the_last_track(): void
    {
        $queue = $this->queue();
        $queue->append([8, 9]);

        $this->assertSame([3, 4, 5, 8, 9], $queue->upNext(10));
    }

    public function test_insert_next_goes_right_after_the_current_track_in_order(): void
    {
        $queue = $this->queue();
        $queue->insertNext([8, 9]);

        $this->assertSame([8, 9, 3, 4, 5], $queue->upNext(10));
        $this->assertSame(2, $queue->current());
    }

    public function test_insert_next_into_an_empty_queue_makes_them_the_queue(): void
    {
        $queue = new DeviceQueue;
        $queue->insertNext([8]);

        $this->assertSame(8, $queue->current());
    }

    public function test_remove_takes_an_up_next_position_and_keeps_the_current_track(): void
    {
        $queue = $this->queue();

        $this->assertTrue($queue->remove(2));
        $this->assertSame([3, 5], $queue->upNext(10));
        $this->assertSame(2, $queue->current());
        $this->assertFalse($queue->remove(3));
        $this->assertFalse($queue->remove(0));
    }

    public function test_move_puts_the_entry_at_the_target_position(): void
    {
        $queue = $this->queue();
        $this->assertTrue($queue->move(3, 1));
        $this->assertSame([5, 3, 4], $queue->upNext(10));

        $this->assertTrue($queue->move(1, 3));
        $this->assertSame([3, 4, 5], $queue->upNext(10));
        $this->assertSame(2, $queue->current());
    }

    public function test_move_outside_the_up_next_list_changes_nothing(): void
    {
        $queue = $this->queue();

        $this->assertFalse($queue->move(1, 4));
        $this->assertFalse($queue->move(0, 1));
        $this->assertSame([3, 4, 5], $queue->upNext(10));
    }

    public function test_clear_keeps_only_what_plays(): void
    {
        $queue = $this->queue();
        $queue->clear();

        $this->assertSame([], $queue->upNext(10));
        $this->assertSame(2, $queue->current());
    }
}
