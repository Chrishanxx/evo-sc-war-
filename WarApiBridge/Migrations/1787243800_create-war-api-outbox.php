<?php

namespace EvoSC\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

class CreateWarApiOutbox extends Migration
{
    public function up(Builder $schema)
    {
        $schema->create('war-api-outbox', function (Blueprint $t) {
            $t->increments('id');
            $t->string('payload_hash', 64)->unique();
            $t->mediumText('payload');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->dateTime('available_at');
            $t->dateTime('created_at');
            $t->dateTime('sent_at')->nullable();
            $t->text('last_error')->nullable();
            $t->index(['sent_at', 'available_at']);
        });
    }

    public function down(Builder $schema)
    {
        $schema->dropIfExists('war-api-outbox');
    }
}
