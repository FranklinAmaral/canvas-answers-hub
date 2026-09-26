<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canvas_environments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->string('environment_type', 50)->default('production');
            $table->string('base_url');
            $table->text('api_token');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('lti_enabled')->default(false);
            $table->string('lti_issuer')->nullable();
            $table->string('lti_client_id')->nullable();
            $table->string('lti_deployment_id')->nullable();
            $table->string('lti_authorization_endpoint')->nullable();
            $table->string('lti_jwks_uri')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
            $table->unique(
                ['lti_issuer', 'lti_client_id', 'lti_deployment_id'],
                'canvas_env_lti_registration_unique'
            );
            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'environment_type']);
            $table->index('lti_enabled', 'canvas_env_lti_enabled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canvas_environments');
    }
};
