<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiGrader;

use App\Http\Controllers\Controller;
use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderCorrectionItem;
use App\Models\AiGraderQuizConfig;
use App\Models\CanvasEnvironment;
use App\Models\Organization;
use App\Services\AiGrader\AiGraderSanitizer;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AiGraderCorrectionItemController extends Controller
{
    public function __construct(
        private readonly AiGraderSanitizer $sanitizer,
    ) {
    }

    public function index(Request $request): View
    {
        $query = AiGraderCorrectionItem::query()
            ->with(['organization', 'canvasEnvironment', 'blueprintConfig', 'quizConfig', 'questionConfig']);

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->integer('organization_id'));
        }

        if ($request->filled('canvas_environment_id')) {
            $query->where('canvas_environment_id', $request->integer('canvas_environment_id'));
        }

        if ($request->filled('ai_grader_blueprint_config_id')) {
            $query->where('ai_grader_blueprint_config_id', $request->integer('ai_grader_blueprint_config_id'));
        }

        if ($request->filled('ai_grader_quiz_config_id')) {
            $query->where('ai_grader_quiz_config_id', $request->integer('ai_grader_quiz_config_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $childCourseId = trim((string) $request->input('child_course_id', ''));
        $canvasUserId = trim((string) $request->input('canvas_user_id', ''));
        $quizSubmissionId = trim((string) $request->input('canvas_quiz_submission_id', ''));
        $questionId = trim((string) $request->input('canvas_question_id', ''));
        $searchTerm = trim((string) $request->input('q', ''));

        if ($childCourseId !== '') {
            $query->where('child_course_id', $childCourseId);
        }

        if ($canvasUserId !== '') {
            $query->where(function (Builder $query) use ($canvasUserId): void {
                $query->where('canvas_user_id', $canvasUserId)
                    ->orWhere('canvas_user_name', 'like', "%{$canvasUserId}%")
                    ->orWhere('canvas_user_login_id', 'like', "%{$canvasUserId}%");
            });
        }

        if ($quizSubmissionId !== '') {
            $query->where('canvas_quiz_submission_id', $quizSubmissionId);
        }

        if ($questionId !== '') {
            $query->where('canvas_question_id', $questionId);
        }

        if ($searchTerm !== '') {
            $query->where(function (Builder $query) use ($searchTerm): void {
                $query->where('canvas_user_name', 'like', "%{$searchTerm}%")
                    ->orWhere('canvas_user_login_id', 'like', "%{$searchTerm}%")
                    ->orWhere('canvas_quiz_id', 'like', "%{$searchTerm}%")
                    ->orWhere('canvas_question_name', 'like', "%{$searchTerm}%")
                    ->orWhere('canvas_question_id', 'like', "%{$searchTerm}%");
            });
        }

        $query->latest();

        $items = (clone $query)->paginate(15)->withQueryString();
        $summary = $this->summaryFor((clone $query));

        return view('ai-grader.correction-items.index', [
            'items' => $items,
            'summary' => $summary,
            'organizations' => Organization::query()->orderBy('name')->get(),
            'environments' => CanvasEnvironment::query()->with('organization')->orderBy('name')->get(),
            'blueprints' => AiGraderBlueprintConfig::query()->with('organization')->orderByDesc('updated_at')->get(),
            'quizzes' => AiGraderQuizConfig::query()->with('blueprintConfig')->orderBy('canvas_quiz_title')->get(),
            'statuses' => $this->statuses(),
        ]);
    }

    public function show(AiGraderCorrectionItem $correctionItem): View
    {
        $correctionItem->load([
            'organization',
            'canvasEnvironment',
            'batch',
            'blueprintConfig',
            'quizConfig',
            'questionConfig',
            'publicationLogs',
            'usageLogs',
            'teacherActions.actor',
        ]);

        return view('ai-grader.correction-items.show', [
            'correctionItem' => $correctionItem,
            'metadataJson' => $this->toPrettyJson($this->sanitizer->sanitizeArray($correctionItem->metadata ?? [])),
            'aiRawResponseJson' => $this->toPrettyJson($this->sanitizer->sanitizeArray($correctionItem->ai_raw_response ?? [])),
            'aiReviewFlagsJson' => $this->toPrettyJson($this->sanitizer->sanitizeArray($correctionItem->ai_review_flags ?? [])),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function summaryFor(Builder $query): array
    {
        $statuses = $query
            ->reorder()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $value): int => (int) $value);

        return [
            'total' => $statuses->sum(),
            'pending' => $statuses[AiGraderCorrectionItem::STATUS_PENDING] ?? 0,
            'pending_quota' => $statuses[AiGraderCorrectionItem::STATUS_PENDING_QUOTA] ?? 0,
            'skipped_blank_answer' => $statuses[AiGraderCorrectionItem::STATUS_SKIPPED_BLANK_ANSWER] ?? 0,
            'ai_corrected' => $statuses[AiGraderCorrectionItem::STATUS_AI_CORRECTED] ?? 0,
            'pending_teacher_approval' => $statuses[AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL] ?? 0,
            'published_to_canvas' => $statuses[AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS] ?? 0,
            'failed' => $statuses[AiGraderCorrectionItem::STATUS_FAILED] ?? 0,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statuses(): array
    {
        return [
            AiGraderCorrectionItem::STATUS_PENDING => 'pending',
            AiGraderCorrectionItem::STATUS_PENDING_QUOTA => 'pending_quota',
            AiGraderCorrectionItem::STATUS_PENDING_CONFIGURATION => 'pending_configuration',
            AiGraderCorrectionItem::STATUS_SKIPPED_BLANK_ANSWER => 'skipped_blank_answer',
            AiGraderCorrectionItem::STATUS_AI_CORRECTED => 'ai_corrected',
            AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL => 'pending_teacher_approval',
            AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS => 'published_to_canvas',
            AiGraderCorrectionItem::STATUS_FAILED => 'failed',
        ];
    }

    /**
     * @param array<string|int, mixed> $value
     */
    private function toPrettyJson(array $value): string
    {
        if ($value === []) {
            return '{}';
        }

        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{}';
    }
}
