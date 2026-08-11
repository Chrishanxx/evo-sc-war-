<?php

namespace EvoSC\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

class AddWarPlayerNames extends Migration
{
    public function up(Builder $schema)
    {
        $schema->table('war-players', function (Blueprint $table) {
            $table->string('original_name')->nullable();
            $table->string('war_display_name')->nullable();
        });
    }

    public function down(Builder $schema)
    {
        $schema->table('war-players', function (Blueprint $table) {
            $table->dropColumn(['original_name', 'war_display_name']);
        });
    }
}
