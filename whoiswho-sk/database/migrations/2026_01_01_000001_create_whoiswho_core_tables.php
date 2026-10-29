<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->string('ico', 8)->primary();
            $table->string('name')->nullable();
            $table->string('name_norm')->nullable();
            $table->string('status')->nullable();
            $table->string('legal_form')->nullable();
            $table->string('legal_form_code')->nullable();
            $table->string('seat_norm')->nullable();
            $table->string('street')->nullable();
            $table->string('municipality')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('country', 2)->default('SK');
            $table->string('dic', 12)->nullable();
            $table->string('ic_dph', 12)->nullable();
            $table->date('established_on')->nullable();
            $table->date('terminated_on')->nullable();
            $table->jsonb('raw')->nullable();
            $table->jsonb('sources')->nullable();
            $table->timestamp('retrieved_at')->nullable();
            $table->timestamps();

            $table->index('seat_norm');
            $table->index('name_norm');
        });

        Schema::create('persons', function (Blueprint $table) {
            $table->id();
            $table->string('name_norm')->unique();
            $table->string('full_name')->nullable();
            $table->string('given_name')->nullable();
            $table->string('family_name')->nullable();
            $table->date('born_on')->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('edges', function (Blueprint $table) {
            $table->id();
            $table->string('from_type', 16);
            $table->string('from_id');
            $table->string('to_type', 16);
            $table->string('to_id');
            $table->string('edge_type', 32);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('source', 32);
            $table->string('source_url')->nullable();
            $table->timestamp('retrieved_at')->nullable();
            $table->string('raw_hash', 64)->nullable();
            $table->decimal('confidence', 4, 3)->default(1.0);
            $table->jsonb('payload')->nullable();
            $table->timestamps();

            $table->unique(
                ['from_type', 'from_id', 'to_type', 'to_id', 'edge_type', 'source'],
                'edges_unique_link'
            );
            $table->index(['from_type', 'from_id']);
            $table->index(['to_type', 'to_id']);
            $table->index('edge_type');
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('ico', 8)->nullable();
            $table->string('caller', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('endpoint', 128)->nullable();
            $table->timestamp('retrieved_at');
            $table->string('raw_hash', 64)->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index('ico');
            $table->index('retrieved_at');
        });

        Schema::create('report_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('ico', 8);
            $table->string('status', 16)->default('draft');
            $table->jsonb('draft')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_jobs');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('edges');
        Schema::dropIfExists('persons');
        Schema::dropIfExists('companies');
    }
};
