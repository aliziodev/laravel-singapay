<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency ledger for inbound SingaPay webhooks.
 *
 * SingaPay retries deliveries that were not acknowledged, so the same event
 * can arrive more than once. Each processed delivery is recorded here by a
 * hash of its body; duplicates are acknowledged with 200 without being
 * re-dispatched. Safe to prune periodically (rows older than a few days are
 * never needed — the gateway's retry window is minutes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('singapay_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('event_type', 64)->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('singapay_webhook_events');
    }
};
