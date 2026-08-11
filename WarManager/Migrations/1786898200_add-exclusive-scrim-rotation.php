<?php

namespace EvoSC\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

class AddExclusiveScrimRotation extends Migration
{
    public function up(Builder $schema)
    {
        $schema->table('wars', function (Blueprint $table) {
            $table->unsignedInteger('map_time_limit')->default(420);
            $table->unsignedInteger('chat_time')->default(15);
            $table->boolean('strict_scrim_maps')->default(true);
            $table->boolean('repeat_playlist')->default(true);
            $table->boolean('restore_after_restart')->default(true);
            $table->boolean('restore_normal_playlist')->default(true);
            $table->string('matchsettings_file')->nullable();
            $table->string('previous_matchsettings')->nullable();
            $table->unsignedInteger('rotation_number')->default(0);
            $table->unsignedInteger('rotation_position')->default(0);
        });
        $schema->table('war-maps', function (Blueprint $table) {
            $table->string('map_file')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
        });
    }

    public function down(Builder $schema)
    {
        $schema->table('war-maps', function (Blueprint $table) {
            $table->dropColumn(['map_file', 'position', 'enabled']);
        });
        $schema->table('wars', function (Blueprint $table) {
            $table->dropColumn([
                'map_time_limit', 'chat_time', 'strict_scrim_maps', 'repeat_playlist',
                'restore_after_restart', 'restore_normal_playlist', 'matchsettings_file',
                'previous_matchsettings', 'rotation_number', 'rotation_position',
            ]);
        });
    }
}
