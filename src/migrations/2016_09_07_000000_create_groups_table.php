<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateGroupsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('groups', function(Blueprint $table)
        {
            $table->increments('id');
            $table->string('name')->index();

            $table->string('slug')->index();
            $table->string('title');
            $table->integer('sorter');
            $table->boolean('show');
            $table->boolean('predefined');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //Schema::drop('group_group');
        Schema::drop('groups');
    }
}
