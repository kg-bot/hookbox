<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hookbox_attempts', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('message_id')->constrained('hookbox_messages')->cascadeOnDelete();
            $table->enum('kind', ['initial', 'replay', 'dry_run']);
            $table->string('handler');
            $table->enum('status', ['pending', 'succeeded', 'failed', 'skipped']);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->longText('error_trace')->nullable();
            $table->string('triggered_by')->nullable();
            $table->timestamps();

            $table->index(['message_id', 'started_at']);
            $table->index('status');
            $table->index(['kind', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hookbox_attempts');
    }
};
