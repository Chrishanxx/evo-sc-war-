<?php

namespace EvoSC\Modules\WarManager\Classes;

use EvoSC\Models\Player;

/** @deprecated Kept as a compatibility bridge for existing installations. */
final class WarScoreboard
{
    public static function mount(Player $player): void
    {
        WarLiveScoreWidget::mount($player);
    }

    public static function show(Player $player): void
    {
        WarLiveScoreWidget::show($player);
    }

    public static function hide(Player $player): void
    {
        WarLiveScoreWidget::hide($player);
    }
}
