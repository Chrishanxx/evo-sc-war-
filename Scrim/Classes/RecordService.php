<?php

namespace EvoSC\Modules\Scrim\Classes;

use EvoSC\Classes\DB;
use EvoSC\Controllers\MapController;
use EvoSC\Models\Player;

final class RecordService
{
    public static function record(Player $player, int $time): void
    {
        ScrimRepository::finishExpired();
        $scrim = ScrimRepository::current();
        if (!$scrim || $scrim->status !== ScrimState::ACTIVE) {
            return;
        }
        $map = MapController::getCurrentMap();
        if (!$map) {
            return;
        }
        if (!DB::table('scrim-maps')->where('scrim_id', $scrim->id)->where('map_uid', $map->uid)->exists()) {
            return;
        }

        $assignment = DB::table('scrim-players')->where('scrim_id', $scrim->id)->where('player_login', $player->Login)->first();
        $detected = TeamDetector::detect($player->NickName, $scrim->team_a, $scrim->team_b);
        if (!$assignment && !$detected) {
            return;
        }
        $team = $assignment ? $assignment->locked_team : $detected['team'];
        $name = $detected ? $detected['name'] : $player->NickName;

        $old = DB::table('scrim-records')->where('scrim_id', $scrim->id)->where('map_uid', $map->uid)
            ->where('player_login', $player->Login)->first();
        if ($old && $old->record_time <= $time) {
            return;
        }
        DB::transaction(static function () use ($scrim, $map, $player, $name, $team, $time): void {
            DB::table('scrim-players')->updateOrInsert(
                ['scrim_id' => $scrim->id, 'player_login' => $player->Login],
                ['display_name' => $name, 'locked_team' => $team, 'updated_at' => gmdate('Y-m-d H:i:s')]
            );
            DB::table('scrim-records')->updateOrInsert(
                ['scrim_id' => $scrim->id, 'map_uid' => $map->uid, 'player_login' => $player->Login],
                ['display_name' => $name, 'team' => $team, 'record_time' => $time, 'recorded_at' => gmdate('Y-m-d H:i:s')]
            );
            self::recalculate((int)$scrim->id, $map->uid);
        });
    }

    public static function recalculate(int $scrimId, string $mapUid): void
    {
        $points = DB::table('scrim-points')->where('scrim_id', $scrimId)->pluck('points', 'rank')->map(static function ($v) { return (int)$v; })->all();
        $rows = DB::table('scrim-records')->where('scrim_id', $scrimId)->where('map_uid', $mapUid)->get();
        $input = [];
        foreach ($rows as $row) {
            $input[] = ['login' => $row->player_login, 'time' => (int)$row->record_time];
        }
        foreach (ScoreCalculator::rank($input, $points) as $ranked) {
            DB::table('scrim-records')->where('scrim_id', $scrimId)->where('map_uid', $mapUid)
                ->where('player_login', $ranked['login'])->update(['rank' => $ranked['rank'], 'points' => $ranked['points']]);
        }
        $totals = DB::table('scrim-records')->where('scrim_id', $scrimId)->selectRaw('player_login, SUM(points) total')
            ->groupBy('player_login')->get();
        foreach ($totals as $total) {
            DB::table('scrim-players')->where('scrim_id', $scrimId)->where('player_login', $total->player_login)
                ->update(['total_points' => (int)$total->total]);
        }
    }
}
