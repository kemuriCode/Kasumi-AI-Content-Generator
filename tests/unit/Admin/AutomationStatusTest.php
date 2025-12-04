<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit\Admin;

use Kasumi\AIGenerator\Admin\AutomationStatus;
use Kasumi\AIGenerator\Cron\Scheduler;
use PHPUnit\Framework\TestCase;
use const HOUR_IN_SECONDS;

final class AutomationStatusTest extends TestCase
{
    public function test_snapshot_formats_scheduler_data(): void
    {
        $scheduler = $this->createMock(Scheduler::class);
        $now = time();
        $scheduler->method("get_status_snapshot")->willReturn([
            "paused" => false,
            "block_reason" => "",
            "status" => [
                "queued_comment_jobs" => 2,
                "last_post_time" => $now - HOUR_IN_SECONDS,
                "last_post_id" => 42,
                "last_error" => "",
                "automation_notice" => "",
            ],
            "cron" => [
                "post" => $now + HOUR_IN_SECONDS,
                "manual" => $now + (2 * HOUR_IN_SECONDS),
                "comment" => $now + (3 * HOUR_IN_SECONDS),
            ],
        ]);

        $payload = AutomationStatus::snapshot($scheduler);

        $this->assertTrue($payload["available"]);
        $this->assertSame("active", $payload["state"]);
        $this->assertSame(2, $payload["queue"]["value"]);
        $this->assertArrayHasKey("meta", $payload);
        $this->assertArrayHasKey("next_post", $payload["meta"]);
        $this->assertSame("42", $payload["last_post_id"]);
    }

    public function test_snapshot_handles_unavailable_scheduler(): void
    {
        $payload = AutomationStatus::snapshot(null);

        $this->assertFalse($payload["available"]);
        $this->assertSame("unavailable", $payload["state"]);
        $this->assertSame("—", $payload["last_post_id"]);
    }
}
