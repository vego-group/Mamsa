<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up()
{
    Schema::table('admin_details', function (Blueprint $table) {
        
        // لو العمود user_id مو موجود أضفه
        if (!Schema::hasColumn('admin_details', 'user_id')) {
            $table->unsignedBigInteger('user_id')->after('id');
        }

        // لو فيه مفتاح أجنبي غلط، نحذفه بشكل آمن
        try {
            $table->dropForeign(['user_id']);
        } catch (\Exception $e) {}

        // إضافة مفتاح صحيح
        $table->foreign('user_id')
              ->references('id')->on('users')
              ->onDelete('cascade');
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admin_details', function (Blueprint $table) {
            //
        });
    }
};
