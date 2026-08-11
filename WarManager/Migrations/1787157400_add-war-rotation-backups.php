<?php

namespace EvoSC\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

class AddWarRotationBackups extends Migration
{
    public function up(Builder $schema)
    {
        $schema->create('war-rotation-backups', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('war_id')->unique();
            $table->string('matchsettings_file');
            $table->longText('original_playlist');
            $table->longText('war_playlist');
            $table->string('status', 24)->default('BACKED_UP');
            $table->dateTime('applied_at')->nullable();
            $table->dateTime('restored_at')->nullable();
            $table->dateTime('last_verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    }

    public function down(Builder $schema)
    {
        $schema->dropIfExists('war-rotation-backups');
    }
}
