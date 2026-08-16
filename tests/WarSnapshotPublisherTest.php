<?php

use EvoSC\Modules\WarApiBridge\Classes\WarApiPublisher;
use PHPUnit\Framework\TestCase;

final class WarSnapshotPublisherTest extends TestCase
{
    public function testItCreatesStableHmacSignature(): void
    {
        self::assertSame(
            hash_hmac('sha256', '{"war":18}', 'test-secret'),
            WarApiPublisher::signature('{"war":18}', 'test-secret')
        );
    }

    public function testRetryDelayUsesCappedExponentialBackoff(): void
    {
        self::assertSame(10, WarApiPublisher::retryDelay(1, 5));
        self::assertSame(40, WarApiPublisher::retryDelay(3, 5));
        self::assertSame(300, WarApiPublisher::retryDelay(12, 5));
    }
}
