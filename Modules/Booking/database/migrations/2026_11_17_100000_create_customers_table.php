<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers are platform-wide accounts (not tenant-scoped): one login can
     * book with any business. Each business still sees the customer as a row
     * in its own `clients` table, linked back via clients.customer_id.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('business_id')
                ->constrained('customers')->nullOnDelete();
            $table->unique(['business_id', 'customer_id']);
        });

        Schema::create('customer_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_password_reset_tokens');

        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'customer_id']);
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('customers');
    }
};
