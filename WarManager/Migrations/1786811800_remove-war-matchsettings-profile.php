<?php

namespace EvoSC\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

class RemoveWarMatchsettingsProfile extends Migration
{
    public function up(Builder $schema)
    {
        $schema->table('wars', function (Blueprint $table) {
            $table->dropColumn([
                'mode_type', 'trackmania_script', 'matchsettings_file', 'team_a_name', 'team_b_name',
                'map_time_limit', 'chat_time', 'restore_after_restart',
            ]);
        });
    }

    public function down(Builder $schema)
    {
        $schema->table('wars', function (Blueprint $table) {
            $table->string('mode_type', 32)->default('WAR');
            $table->string('trackmania_script')->default('Trackmania/TM_TimeAttack_Online.Script.txt');
            $table->string('matchsettings_file')->nullable();
            $table->string('team_a_name')->nullable();
            $table->string('team_b_name')->nullable();
            $table->unsignedInteger('map_time_limit')->default(420);
            $table->unsignedInteger('chat_time')->default(15);
            $table->boolean('restore_after_restart')->default(true);
        });
    }
}
