<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('notify_confirmation_email')->default(true);
            $table->boolean('notify_reminder_email')->default(true);
            $table->unsignedSmallInteger('reminder_lead_hours')->default(24);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['notify_confirmation_email', 'notify_reminder_email', 'reminder_lead_hours']);
        });
    }
};
