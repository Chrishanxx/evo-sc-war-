<?php

namespace EvoSC\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

class AddScoringPauseState extends Migration
{
    public function up(Builder $schema)
    {
        $schema->table('wars', function (Blueprint $table) {
            $table->boolean('scoring_paused')->default(false);
            $table->string('scoring_pause_reason')->nullable();
        });
    }

    public function down(Builder $schema)
    {
        $schema->table('wars', function (Blueprint $table) {
            $table->dropColumn(['scoring_paused', 'scoring_pause_reason']);
        });
    }
}
