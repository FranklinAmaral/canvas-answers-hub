<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_grader_client_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->boolean('enabled')->default(false);
            $table->string('status', 40)->default('inactive');
            $table->string('plan_name')->nullable();
            $table->unsignedInteger('trial_quota_total')->default(50);
            $table->unsignedInteger('purchased_quota_total')->default(0);
            $table->unsignedInteger('quota_used')->default(0);
            $table->unsignedInteger('quota_reserved')->default(0);
            $table->timestamp('quota_reset_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'ai_client_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by', 'ai_client_created_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'ai_client_updated_fk')->references('id')->on('users')->nullOnDelete();

            $table->unique('organization_id', 'ai_client_org_unique');
            $table->index('status', 'ai_client_status_idx');
            $table->index('enabled', 'ai_client_enabled_idx');
        });

        Schema::create('ai_grader_ai_providers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->string('provider_type', 60);
            $table->string('base_url')->nullable();
            $table->string('model')->nullable();
            $table->text('api_key_encrypted')->nullable();
            $table->unsignedInteger('request_timeout_seconds')->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            $table->decimal('temperature', 4, 2)->nullable();
            $table->decimal('input_token_cost', 12, 6)->nullable();
            $table->decimal('output_token_cost', 12, 6)->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'ai_provider_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by', 'ai_provider_created_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'ai_provider_updated_fk')->references('id')->on('users')->nullOnDelete();

            $table->index('organization_id', 'ai_provider_org_idx');
            $table->index('provider_type', 'ai_provider_type_idx');
            $table->index('enabled', 'ai_provider_enabled_idx');
            $table->index('is_default', 'ai_provider_default_idx');
        });

        Schema::create('ai_grader_blueprint_configs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('canvas_environment_id');
            $table->string('blueprint_course_id', 100);
            $table->string('blueprint_course_name')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('publication_mode', 60)->default('teacher_approval');
            $table->string('trigger_mode', 60)->default('manual');
            $table->timestamp('scheduled_at')->nullable();
            $table->boolean('teacher_can_adjust_score')->default(true);
            $table->boolean('teacher_can_adjust_feedback')->default(true);
            $table->boolean('teacher_can_publish')->default(true);
            $table->boolean('teacher_can_republish')->default(true);
            $table->boolean('teacher_can_export')->default(false);
            $table->boolean('teacher_can_view_grading_prompt')->default(false);
            $table->string('default_feedback_language', 20)->default('pt_BR');
            $table->unsignedBigInteger('ai_provider_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'ai_blueprint_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('canvas_environment_id', 'ai_blueprint_env_fk')->references('id')->on('canvas_environments')->cascadeOnDelete();
            $table->foreign('ai_provider_id', 'ai_blueprint_provider_fk')->references('id')->on('ai_grader_ai_providers')->nullOnDelete();
            $table->foreign('created_by', 'ai_blueprint_created_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'ai_blueprint_updated_fk')->references('id')->on('users')->nullOnDelete();

            $table->unique(['organization_id', 'canvas_environment_id', 'blueprint_course_id'], 'ai_blueprint_unique');
            $table->index('organization_id', 'ai_blueprint_org_idx');
            $table->index('canvas_environment_id', 'ai_blueprint_env_idx');
            $table->index('blueprint_course_id', 'ai_blueprint_course_idx');
            $table->index('enabled', 'ai_blueprint_enabled_idx');
        });

        Schema::create('ai_grader_quiz_configs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_grader_blueprint_config_id');
            $table->string('canvas_quiz_id', 100);
            $table->string('canvas_assignment_id', 100);
            $table->string('canvas_quiz_title')->nullable();
            $table->string('canvas_quiz_type', 60)->nullable();
            $table->decimal('points_possible', 10, 2)->nullable();
            $table->unsignedInteger('question_count')->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('correction_enabled')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->foreign('ai_grader_blueprint_config_id', 'ai_quiz_blueprint_fk')->references('id')->on('ai_grader_blueprint_configs')->cascadeOnDelete();

            $table->unique(['ai_grader_blueprint_config_id', 'canvas_quiz_id'], 'ai_quiz_unique');
            $table->index('ai_grader_blueprint_config_id', 'ai_quiz_blueprint_idx');
            $table->index('canvas_quiz_id', 'ai_quiz_canvas_idx');
            $table->index('canvas_assignment_id', 'ai_quiz_assignment_idx');
            $table->index('enabled', 'ai_quiz_enabled_idx');
        });

        Schema::create('ai_grader_question_configs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_grader_quiz_config_id');
            $table->string('canvas_question_id', 100);
            $table->string('canvas_question_name')->nullable();
            $table->string('canvas_question_type', 80);
            $table->longText('canvas_question_text')->nullable();
            $table->decimal('canvas_points_possible', 10, 2)->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('correction_mode', 40)->default('simple');
            $table->longText('ai_grading_instructions')->nullable();
            $table->string('canvas_rubric_id', 100)->nullable();
            $table->string('rubric_title')->nullable();
            $table->json('rubric_snapshot')->nullable();
            $table->timestamp('rubric_synced_at')->nullable();
            $table->longText('canvas_neutral_comments_snapshot')->nullable();
            $table->unsignedInteger('instructions_version')->default(1);
            $table->timestamp('instructions_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->foreign('ai_grader_quiz_config_id', 'ai_question_quiz_fk')->references('id')->on('ai_grader_quiz_configs')->cascadeOnDelete();

            $table->unique(['ai_grader_quiz_config_id', 'canvas_question_id'], 'ai_question_unique');
            $table->index('ai_grader_quiz_config_id', 'ai_question_quiz_idx');
            $table->index('canvas_question_id', 'ai_question_canvas_idx');
            $table->index('canvas_question_type', 'ai_question_type_idx');
            $table->index('enabled', 'ai_question_enabled_idx');
            $table->index('correction_mode', 'ai_question_correction_mode_idx');
            $table->index('canvas_rubric_id', 'ai_question_rubric_idx');
        });

        Schema::create('ai_grader_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('canvas_environment_id');
            $table->unsignedBigInteger('ai_grader_blueprint_config_id')->nullable();
            $table->unsignedBigInteger('ai_grader_quiz_config_id')->nullable();
            $table->string('child_course_id', 100)->nullable();
            $table->string('trigger_type', 60);
            $table->string('status', 60)->default('pending');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('pending_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->unsignedInteger('skipped_items')->default(0);
            $table->unsignedInteger('quota_blocked_items')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'ai_batch_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('canvas_environment_id', 'ai_batch_env_fk')->references('id')->on('canvas_environments')->cascadeOnDelete();
            $table->foreign('ai_grader_blueprint_config_id', 'ai_batch_blueprint_fk')->references('id')->on('ai_grader_blueprint_configs')->nullOnDelete();
            $table->foreign('ai_grader_quiz_config_id', 'ai_batch_quiz_fk')->references('id')->on('ai_grader_quiz_configs')->nullOnDelete();
            $table->foreign('created_by', 'ai_batch_created_fk')->references('id')->on('users')->nullOnDelete();

            $table->index('organization_id', 'ai_batch_org_idx');
            $table->index('canvas_environment_id', 'ai_batch_env_idx');
            $table->index('ai_grader_blueprint_config_id', 'ai_batch_blueprint_idx');
            $table->index('ai_grader_quiz_config_id', 'ai_batch_quiz_idx');
            $table->index('child_course_id', 'ai_batch_child_course_idx');
            $table->index('trigger_type', 'ai_batch_trigger_idx');
            $table->index('status', 'ai_batch_status_idx');
        });

        Schema::create('ai_grader_correction_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('canvas_environment_id');
            $table->unsignedBigInteger('ai_grader_batch_id')->nullable();
            $table->unsignedBigInteger('ai_grader_blueprint_config_id')->nullable();
            $table->unsignedBigInteger('ai_grader_quiz_config_id')->nullable();
            $table->unsignedBigInteger('ai_grader_question_config_id')->nullable();
            $table->string('blueprint_course_id', 100)->nullable();
            $table->string('child_course_id', 100);
            $table->string('child_course_name')->nullable();
            $table->string('canvas_quiz_id', 100);
            $table->string('canvas_assignment_id', 100);
            $table->string('canvas_quiz_submission_id', 100);
            $table->string('canvas_assignment_submission_id', 100)->nullable();
            $table->string('canvas_user_id', 100);
            $table->string('canvas_user_name')->nullable();
            $table->string('canvas_user_login_id')->nullable();
            $table->unsignedInteger('attempt');
            $table->string('canvas_question_id', 100);
            $table->string('canvas_question_name')->nullable();
            $table->longText('canvas_question_text')->nullable();
            $table->decimal('canvas_points_possible', 10, 2);
            $table->longText('answer_html')->nullable();
            $table->longText('answer_text')->nullable();
            $table->longText('ai_grading_instructions_snapshot')->nullable();
            $table->unsignedInteger('instructions_version')->nullable();
            $table->string('correction_mode', 40)->default('simple');
            $table->string('canvas_rubric_id', 100)->nullable();
            $table->string('rubric_title')->nullable();
            $table->json('rubric_snapshot')->nullable();
            $table->string('status', 60)->default('pending');
            $table->string('publication_mode', 60)->nullable();
            $table->decimal('ai_score', 10, 2)->nullable();
            $table->decimal('ai_rubric_percentage', 6, 2)->nullable();
            $table->longText('ai_feedback')->nullable();
            $table->json('ai_rubric_feedback')->nullable();
            $table->timestamp('ai_corrected_at')->nullable();
            $table->string('ai_provider_type', 80)->nullable();
            $table->string('ai_provider_model')->nullable();
            $table->json('ai_raw_response')->nullable();
            $table->string('ai_confidence', 60)->nullable();
            $table->json('ai_review_flags')->nullable();
            $table->decimal('final_score', 10, 2)->nullable();
            $table->longText('final_feedback')->nullable();
            $table->json('teacher_rubric_feedback')->nullable();
            $table->string('review_status', 60)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewer_canvas_user_id', 100)->nullable();
            $table->string('reviewer_name')->nullable();
            $table->string('reviewer_login_id')->nullable();
            $table->decimal('canvas_published_score', 10, 2)->nullable();
            $table->longText('canvas_published_feedback')->nullable();
            $table->json('published_rubric_feedback')->nullable();
            $table->text('canvas_publication_error')->nullable();
            $table->unsignedBigInteger('teacher_user_id')->nullable();
            $table->timestamp('teacher_adjusted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'ai_item_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('canvas_environment_id', 'ai_item_env_fk')->references('id')->on('canvas_environments')->cascadeOnDelete();
            $table->foreign('ai_grader_batch_id', 'ai_item_batch_fk')->references('id')->on('ai_grader_batches')->nullOnDelete();
            $table->foreign('ai_grader_blueprint_config_id', 'ai_item_blueprint_fk')->references('id')->on('ai_grader_blueprint_configs')->nullOnDelete();
            $table->foreign('ai_grader_quiz_config_id', 'ai_item_quiz_fk')->references('id')->on('ai_grader_quiz_configs')->nullOnDelete();
            $table->foreign('ai_grader_question_config_id', 'ai_item_question_fk')->references('id')->on('ai_grader_question_configs')->nullOnDelete();
            $table->foreign('teacher_user_id', 'ai_item_teacher_fk')->references('id')->on('users')->nullOnDelete();

            $table->unique([
                'organization_id',
                'canvas_environment_id',
                'child_course_id',
                'canvas_quiz_id',
                'canvas_quiz_submission_id',
                'attempt',
                'canvas_question_id',
                'canvas_user_id',
            ], 'ai_item_idempotency_unique');
            $table->index('organization_id', 'ai_item_org_idx');
            $table->index('canvas_environment_id', 'ai_item_env_idx');
            $table->index('ai_grader_batch_id', 'ai_item_batch_idx');
            $table->index('ai_grader_question_config_id', 'ai_item_question_idx');
            $table->index('child_course_id', 'ai_item_child_course_idx');
            $table->index('canvas_quiz_id', 'ai_item_quiz_canvas_idx');
            $table->index('canvas_user_id', 'ai_item_user_idx');
            $table->index('status', 'ai_item_status_idx');
            $table->index('review_status', 'ai_item_review_status_idx');
            $table->index('correction_mode', 'ai_item_correction_mode_idx');
            $table->index('canvas_rubric_id', 'ai_item_rubric_idx');
        });

        Schema::create('ai_grader_usage_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('canvas_environment_id');
            $table->unsignedBigInteger('ai_grader_correction_item_id')->nullable();
            $table->unsignedBigInteger('ai_grader_batch_id')->nullable();
            $table->unsignedBigInteger('ai_provider_id')->nullable();
            $table->string('usage_type', 60);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->decimal('estimated_cost', 12, 6)->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('status', 60)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('organization_id', 'ai_usage_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('canvas_environment_id', 'ai_usage_env_fk')->references('id')->on('canvas_environments')->cascadeOnDelete();
            $table->foreign('ai_grader_correction_item_id', 'ai_usage_item_fk')->references('id')->on('ai_grader_correction_items')->nullOnDelete();
            $table->foreign('ai_grader_batch_id', 'ai_usage_batch_fk')->references('id')->on('ai_grader_batches')->nullOnDelete();
            $table->foreign('ai_provider_id', 'ai_usage_provider_fk')->references('id')->on('ai_grader_ai_providers')->nullOnDelete();

            $table->index('organization_id', 'ai_usage_org_idx');
            $table->index('canvas_environment_id', 'ai_usage_env_idx');
            $table->index('ai_grader_correction_item_id', 'ai_usage_item_idx');
            $table->index('ai_grader_batch_id', 'ai_usage_batch_idx');
            $table->index('ai_provider_id', 'ai_usage_provider_idx');
            $table->index('usage_type', 'ai_usage_type_idx');
            $table->index('created_at', 'ai_usage_created_idx');
        });

        Schema::create('ai_grader_canvas_publication_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_grader_correction_item_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('canvas_environment_id');
            $table->string('endpoint');
            $table->string('http_method', 20);
            $table->json('payload')->nullable();
            $table->unsignedInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->boolean('success')->default(false);
            $table->decimal('published_score', 10, 2)->nullable();
            $table->longText('published_feedback')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('ai_grader_correction_item_id', 'ai_pub_item_fk')->references('id')->on('ai_grader_correction_items')->cascadeOnDelete();
            $table->foreign('organization_id', 'ai_pub_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('canvas_environment_id', 'ai_pub_env_fk')->references('id')->on('canvas_environments')->cascadeOnDelete();

            $table->index('ai_grader_correction_item_id', 'ai_pub_item_idx');
            $table->index('organization_id', 'ai_pub_org_idx');
            $table->index('canvas_environment_id', 'ai_pub_env_idx');
            $table->index('success', 'ai_pub_success_idx');
            $table->index('created_at', 'ai_pub_created_idx');
        });

        Schema::create('ai_grader_teacher_actions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ai_grader_correction_item_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action', 60);
            $table->decimal('previous_score', 10, 2)->nullable();
            $table->decimal('new_score', 10, 2)->nullable();
            $table->longText('previous_feedback')->nullable();
            $table->longText('new_feedback')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('ai_grader_correction_item_id', 'ai_action_item_fk')->references('id')->on('ai_grader_correction_items')->cascadeOnDelete();
            $table->foreign('organization_id', 'ai_action_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'ai_action_actor_fk')->references('id')->on('users')->nullOnDelete();

            $table->index('ai_grader_correction_item_id', 'ai_action_item_idx');
            $table->index('organization_id', 'ai_action_org_idx');
            $table->index('actor_user_id', 'ai_action_actor_idx');
            $table->index('action', 'ai_action_type_idx');
            $table->index('created_at', 'ai_action_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_grader_teacher_actions');
        Schema::dropIfExists('ai_grader_canvas_publication_logs');
        Schema::dropIfExists('ai_grader_usage_logs');
        Schema::dropIfExists('ai_grader_correction_items');
        Schema::dropIfExists('ai_grader_batches');
        Schema::dropIfExists('ai_grader_question_configs');
        Schema::dropIfExists('ai_grader_quiz_configs');
        Schema::dropIfExists('ai_grader_blueprint_configs');
        Schema::dropIfExists('ai_grader_ai_providers');
        Schema::dropIfExists('ai_grader_client_settings');
    }
};
