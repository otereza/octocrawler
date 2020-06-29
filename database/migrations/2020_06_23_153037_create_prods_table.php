<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProdsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('prods', function (Blueprint $table) {
            $table->id();
            $table->string('url');
            $table->string('title');
            $table->text('images')->nullable(true);;
            $table->integer('category_id');
            $table->string('category_name');
            $table->string('vendor')->nullable(true);;
            $table->string('packing')->nullable(true);
            $table->string('currency');
            $table->decimal('regular_price', 12, 2);
            $table->decimal('special_price', 12, 2)->nullable(true);
            $table->dateTime('special_price_end_date')->nullable(true);
            $table->text('description')->nullable(true);
            $table->text('params')->nullable(true);
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
        Schema::dropIfExists('prods');
    }
}
