<?php

use EvoSC\Modules\WarManager\Classes\WarSnapshotPublisher;
use PHPUnit\Framework\TestCase;

final class WarSnapshotPublisherTest extends TestCase
{
    public function testItCreatesStableHmacSignature(): void
    {
        self::assertSame(
            hash_hmac('sha256', '{"war":18}', 'test-secret'),
            WarSnapshotPublisher::signature('{"war":18}', 'test-secret')
        );
    }
}
