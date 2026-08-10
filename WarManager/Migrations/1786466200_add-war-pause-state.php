<?php

namespace EvoSC\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

class AddWarPauseState extends Migration
{
    public function up(Builder $schema)
    {
        $schema->table('wars', function (Blueprint $table) {
            $table->dateTime('paused_at')->nullable();
            $table->unsignedInteger('paused_seconds')->default(0);
        });
    }

    public function down(Builder $schema)
    {
        $schema->table('wars', function (Blueprint $table) {
            $table->dropColumn(['paused_at', 'paused_seconds']);
        });
    }
}
