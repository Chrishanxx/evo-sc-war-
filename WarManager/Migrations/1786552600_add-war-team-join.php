<?php

namespace EvoSC\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

class AddWarTeamJoin extends Migration
{
    public function up(Builder $schema)
    {
        $schema->table('wars', function (Blueprint $table) {
            $table->boolean('overlay_join_enabled')->default(true);
            $table->boolean('nickname_detection_enabled')->default(true);
            $table->boolean('allow_team_switch')->default(false);
            $table->unsignedSmallInteger('team_limit')->nullable();
        });
        $schema->table('war-players', function (Blueprint $table) {
            $table->dateTime('joined_at')->nullable();
            $table->string('assigned_by', 16)->default('nickname');
        });
        $schema->create('war-pending-records', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('war_id');
            $table->string('map_uid');
            $table->string('player_login');
            $table->string('display_name');
            $table->unsignedInteger('record_time');
            $table->dateTime('recorded_at');
            $table->unique(['war_id', 'map_uid', 'player_login']);
            $table->index(['war_id', 'player_login']);
        });
    }

    public function down(Builder $schema)
    {
        $schema->dropIfExists('war-pending-records');
        $schema->table('war-players', function (Blueprint $table) {
            $table->dropColumn(['joined_at', 'assigned_by']);
        });
        $schema->table('wars', function (Blueprint $table) {
            $table->dropColumn(['overlay_join_enabled', 'nickname_detection_enabled', 'allow_team_switch', 'team_limit']);
        });
    }
}
