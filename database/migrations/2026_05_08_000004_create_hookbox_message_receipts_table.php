<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hookbox_message_receipts', static function (Blueprint $table): void {
            $table->foreignUlid('message_id')->primary()->constrained('hookbox_messages')->cascadeOnDelete();
            $table->string('method');
            $table->text('url');
            $table->json('headers');
            $table->longText('body');
            $table->string('client_ip')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hookbox_message_receipts');
    }
};
