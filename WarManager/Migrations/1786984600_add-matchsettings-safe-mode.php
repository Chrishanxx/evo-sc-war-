<?php

namespace EvoSC\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

class AddMatchsettingsSafeMode extends Migration
{
    public function up(Builder $schema)
    {
        $schema->table('wars', function (Blueprint $table) {
            $table->boolean('exclusive_rotation')->default(false);
            $table->boolean('matchsettings_safe_mode')->default(true);
            $table->boolean('auto_load_matchsettings')->default(false);
            $table->boolean('auto_restore_matchsettings')->default(false);
        });
    }

    public function down(Builder $schema)
    {
        $schema->table('wars', function (Blueprint $table) {
            $table->dropColumn([
                'exclusive_rotation', 'matchsettings_safe_mode',
                'auto_load_matchsettings', 'auto_restore_matchsettings',
            ]);
        });
    }
}
