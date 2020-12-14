<?php namespace Voilaah\OmiseMall\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class UpdateOfflineMallCustomerOmiseId extends Migration
{
    public function up()
    {
        Schema::table('offline_mall_customers', function ($table) {
            $table->string('omise_customer_id')->nullable();
        });
    }

    public function down()
    {
        Schema::table('offline_mall_customers', function ($table) {
            $table->dropColumn(['omise_customer_id']);
        });
    }
}