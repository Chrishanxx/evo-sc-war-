<?php

namespace EvoSC\Modules\WarApiBridge;

use EvoSC\Classes\Hook;
use EvoSC\Classes\Module;
use EvoSC\Classes\Timer;
use EvoSC\Interfaces\ModuleInterface;
use EvoSC\Modules\WarApiBridge\Classes\WarApiPublisher;

class WarApiBridge extends Module implements ModuleInterface
{
    public static function start(string $mode, bool $isBoot = false)
    {
        if (!config('war-api-bridge.enabled', false)) {
            return;
        }

        Hook::add('WarDataChanged', [WarApiPublisher::class, 'markDirty']);
        Timer::create('war-api-bridge.capture', [WarApiPublisher::class, 'capture'], '5s', true);
        Timer::create('war-api-bridge.pump', [WarApiPublisher::class, 'pump'], '1s', true);
        WarApiPublisher::capture();
    }
}
