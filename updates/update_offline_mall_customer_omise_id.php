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
        if (Schema::hasTable('offline_mall_customers')) {
            Schema::table('offline_mall_customers', function ($table) {
                if (Schema::hasColumn('offline_mall_customers', 'omise_customer_id')) {
                    $table->dropColumn(['omise_customer_id']);
                });
            });
        }
    }
}
