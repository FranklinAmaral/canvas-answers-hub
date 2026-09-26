<?php

declare(strict_types=1);

namespace App\Services\Canvas\Quizzes;

use App\Services\Canvas\CanvasApiClient;
use Illuminate\Support\Collection;
use RuntimeException;

final class CanvasQuizzesService
{
    private const SPIKE_MAX_PAGES = 20;

    public function __construct(
        private readonly CanvasApiClient $client,
    ) {
    }

    public function listQuizzes(int|string $courseId): Collection
    {
        return $this->client->getPaginated("/api/v1/courses/{$courseId}/quizzes?per_page=100");
    }

    public function listQuizzesForSpike(int|string $courseId): Collection
    {
        return $this->client->getPaginated(
            "/api/v1/courses/{$courseId}/quizzes?per_page=100",
            self::SPIKE_MAX_PAGES,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getQuiz(int|string $courseId, int|string $quizId): array
    {
        return $this->client->get("/api/v1/courses/{$courseId}/quizzes/{$quizId}");
    }

    public function listQuestions(int|string $courseId, int|string $quizId): Collection
    {
        return $this->client->getPaginated(
            "/api/v1/courses/{$courseId}/quizzes/{$quizId}/questions?per_page=100",
            self::SPIKE_MAX_PAGES,
        );
    }

    public function listRubrics(int|string $courseId): Collection
    {
        return $this->client->getPaginated(
            "/api/v1/courses/{$courseId}/rubrics?per_page=100&include[]=associations&include[]=data",
            self::SPIKE_MAX_PAGES,
        );
    }

    public function listSubmissions(int|string $courseId, int|string $quizId): Collection
    {
        return $this->client->getPaginated(
            "/api/v1/courses/{$courseId}/quizzes/{$quizId}/submissions?per_page=100",
            self::SPIKE_MAX_PAGES,
            'quiz_submissions',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSubmission(int|string $courseId, int|string $quizId, int|string $quizSubmissionId): ?array
    {
        $data = $this->client->get(
            "/api/v1/courses/{$courseId}/quizzes/{$quizId}/submissions/{$quizSubmissionId}"
        );

        $submissions = $data['quiz_submissions'] ?? null;

        if (is_array($submissions) && isset($submissions[0]) && is_array($submissions[0])) {
            return $submissions[0];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubmissionWithIncludes(int|string $courseId, int|string $quizId, int|string $quizSubmissionId): array
    {
        return $this->client->get(
            "/api/v1/courses/{$courseId}/quizzes/{$quizId}/submissions/{$quizSubmissionId}?include[]=submission&include[]=quiz&include[]=user"
        );
    }

    public function listSubmissionsWithIncludes(int|string $courseId, int|string $quizId): Collection
    {
        return $this->client->getPaginated(
            "/api/v1/courses/{$courseId}/quizzes/{$quizId}/submissions?per_page=100&include[]=submission&include[]=quiz&include[]=user",
            self::SPIKE_MAX_PAGES,
            'quiz_submissions',
        );
    }

    public function listSubmissionQuestions(int|string $quizSubmissionId): Collection
    {
        return $this->client->getPaginated(
            "/api/v1/quiz_submissions/{$quizSubmissionId}/questions?include[]=quiz_question&per_page=100",
            self::SPIKE_MAX_PAGES,
            'quiz_submission_questions',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubmissionQuestionsRaw(int|string $quizSubmissionId): array
    {
        return $this->client->get("/api/v1/quiz_submissions/{$quizSubmissionId}/questions");
    }

    /**
     * @return array<string, mixed>
     */
    public function getAssignmentSubmission(int|string $courseId, int|string $assignmentId, int|string $userId): array
    {
        return $this->client->get(
            "/api/v1/courses/{$courseId}/assignments/{$assignmentId}/submissions/{$userId}?include[]=submission_history&include[]=submission_comments&include[]=rubric_assessment&include[]=full_rubric_assessment&include[]=visibility&include[]=user&include[]=submission_html_comments"
        );
    }

    public function listAssignmentSubmissions(int|string $courseId, int|string $assignmentId): Collection
    {
        return $this->client->getPaginated(
            "/api/v1/courses/{$courseId}/assignments/{$assignmentId}/submissions?per_page=100&include[]=submission_history&include[]=submission_comments&include[]=rubric_assessment&include[]=full_rubric_assessment&include[]=visibility&include[]=user&include[]=submission_html_comments",
            self::SPIKE_MAX_PAGES,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function updateQuestionScoreAndComment(
        int|string $courseId,
        int|string $quizId,
        int|string $quizSubmissionId,
        int $attempt,
        int|string $questionId,
        float $score,
        string $comment,
    ): array {
        return $this->client->put(
            "/api/v1/courses/{$courseId}/quizzes/{$quizId}/submissions/{$quizSubmissionId}",
            [
                'quiz_submissions' => [
                    [
                        'attempt' => $attempt,
                        'questions' => [
                            (string) $questionId => [
                                'score' => $score,
                                'comment' => $comment,
                            ],
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findQuizByTitle(int|string $courseId, string $quizTitle): ?array
    {
        $normalizedTarget = $this->normalizeTitle($quizTitle);

        $matches = $this->listQuizzes($courseId)
            ->filter(function (array $quiz) use ($normalizedTarget): bool {
                return $this->normalizeTitle((string) ($quiz['title'] ?? '')) === $normalizedTarget;
            })
            ->values();

        if ($matches->count() > 1) {
            throw new RuntimeException(sprintf(
                'Mais de um Classic Quiz com o título "%s" foi encontrado no curso %s.',
                $quizTitle,
                $courseId
            ));
        }

        return $matches->first();
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function updateAnswerVisibility(
        int|string $courseId,
        int|string $quizId,
        array $settings,
    ): array {
        $payload = [
            'quiz' => [
                'show_correct_answers' => (bool) $settings['show_correct_answers'],
                'show_correct_answers_at' => $settings['show_correct_answers_at'] ?? null,
                'hide_correct_answers_at' => $settings['hide_correct_answers_at'] ?? null,
                'notify_of_update' => false,
            ],
        ];

        return $this->client->put(
            "/api/v1/courses/{$courseId}/quizzes/{$quizId}",
            $payload
        );
    }

    private function normalizeTitle(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }
}
