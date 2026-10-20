<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('port_opener_ipv4_installations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('node_id')->unique();
            // Keep the reference on deletion: losing a server must not silently create a replacement identity.
            $table->unsignedBigInteger('server_id')->nullable()->unique();
            $table->unsignedBigInteger('requested_by');
            $table->uuid('operation_id');
            $table->string('status', 32)->default('queued');
            $table->string('mode', 16)->default('resume');
            $table->string('resume_phase', 32)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('service_started_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve managed service identity and router ownership state before removing installations.');
    }
};
