<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hookbox_messages', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_id')->nullable()->constrained('hookbox_sources');
            $table->string('idempotency_key')->nullable();
            $table->string('event_type')->nullable();
            $table->json('headers');
            $table->longText('body');
            $table->char('body_hash', 64);
            $table->enum('signature_status', ['valid', 'invalid', 'skipped']);
            $table->timestamp('received_at');
            $table->string('client_ip')->nullable();
            $table->timestamp('redacted_at')->nullable();
            $table->timestamps();

            $table->rawIndex('source_id, received_at desc', 'hookbox_messages_source_id_received_at_index');
            $table->unique(['source_id', 'idempotency_key']);
            $table->index('signature_status');
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hookbox_messages');
    }
};
