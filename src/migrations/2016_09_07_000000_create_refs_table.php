<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateRefsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('refs', function(Blueprint $table)
        {
            $table->increments('id');
            $table->string('entity_name');
            $table->integer('entity_id');
            $table->string('name');
            $table->string('ref_entity_name');
            $table->integer('ref_entity_id');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('refs');
    }
}
