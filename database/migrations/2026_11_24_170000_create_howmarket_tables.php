<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HowMarket: crowdsourced market prices. People report what an item costs in a
 * market; the server keeps the reports honest (daily limit, one vote per person,
 * trust scores) and the app shows a consensus price that one wrong report can't move.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('area', 40)->default('Ibadan')->after('email');
            // Reporter trust, 0.2–2. Only the server changes it.
            $table->decimal('trust', 4, 2)->default(1)->after('area');
        });

        Schema::create('markets', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name', 80);
            $table->string('area', 40)->index();
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name', 80);
            $table->string('unit', 40);
            $table->string('emoji', 16)->default('');
            $table->timestamps();
        });

        Schema::create('price_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('item_id', 40);
            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
            $table->string('market_id', 40);
            $table->foreign('market_id')->references('id')->on('markets')->cascadeOnDelete();
            $table->unsignedBigInteger('price'); // naira
            $table->timestamps();
            $table->index(['market_id', 'item_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('report_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('price_reports')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('vote'); // 1 confirm, -1 looks wrong
            $table->timestamps();
            $table->unique(['report_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_votes');
        Schema::dropIfExists('price_reports');
        Schema::dropIfExists('items');
        Schema::dropIfExists('markets');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['area', 'trust']));
    }
};
