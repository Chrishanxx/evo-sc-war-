<?php

namespace EvoSC\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

class CreateWarManagerTables extends Migration
{
    public function up(Builder $schema)
    {
        $schema->create('wars', function (Blueprint $t) {
            $t->increments('id'); $t->string('name'); $t->string('team_a'); $t->string('team_b');
            $t->unsignedTinyInteger('duration_days'); $t->string('status', 16); $t->dateTime('start_at')->nullable();
            $t->dateTime('end_at')->nullable(); $t->dateTime('finished_at')->nullable(); $t->boolean('map_pool_locked')->default(false);
            $t->boolean('points_locked')->default(false); $t->string('created_by'); $t->dateTime('created_at'); $t->dateTime('updated_at');
        });
        $schema->create('war-maps', function (Blueprint $t) {
            $t->increments('id'); $t->unsignedInteger('war_id'); $t->string('map_uid'); $t->string('map_name');
            $t->unique(['war_id', 'map_uid']); $t->index('war_id');
        });
        $schema->create('war-points', function (Blueprint $t) {
            $t->increments('id'); $t->unsignedInteger('war_id'); $t->unsignedSmallInteger('rank'); $t->unsignedInteger('points');
            $t->unique(['war_id', 'rank']);
        });
        $schema->create('war-players', function (Blueprint $t) {
            $t->increments('id'); $t->unsignedInteger('war_id'); $t->string('player_login'); $t->string('display_name');
            $t->string('locked_team'); $t->unsignedInteger('total_points')->default(0); $t->dateTime('updated_at');
            $t->unique(['war_id', 'player_login']); $t->index(['war_id', 'locked_team']);
        });
        $schema->create('war-records', function (Blueprint $t) {
            $t->increments('id'); $t->unsignedInteger('war_id'); $t->string('map_uid'); $t->string('player_login');
            $t->string('display_name'); $t->string('team'); $t->unsignedInteger('record_time'); $t->unsignedSmallInteger('rank')->nullable();
            $t->unsignedInteger('points')->default(0); $t->dateTime('recorded_at');
            $t->unique(['war_id', 'map_uid', 'player_login']); $t->index(['war_id', 'map_uid', 'rank']);
        });
        $schema->create('war-admin-log', function (Blueprint $t) {
            $t->increments('id'); $t->unsignedInteger('war_id'); $t->string('admin_login'); $t->string('action');
            $t->text('data')->nullable(); $t->dateTime('created_at'); $t->index(['war_id', 'created_at']);
        });
    }

    public function down(Builder $schema)
    {
        foreach (['war-admin-log', 'war-records', 'war-players', 'war-points', 'war-maps', 'wars'] as $table) {
            $schema->dropIfExists($table);
        }
    }
}
