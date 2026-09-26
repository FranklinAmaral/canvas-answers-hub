<?php

declare(strict_types=1);

namespace App\Services\AiGrader\Providers;

use App\Models\AiGraderAiProvider;
use App\Models\AiGraderCorrectionItem;
use App\Services\AiGrader\AiGraderPromptBuilder;
use App\Services\AiGrader\AiGraderSanitizer;
use App\Services\AiGrader\Contracts\AiGraderProviderInterface;
use App\Services\AiGrader\Data\AiGraderPromptContext;
use App\Services\AiGrader\Data\AiGraderResult;
use App\Support\AiGraderLtiView;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class LmStudioAiGraderProvider implements AiGraderProviderInterface
{
    public function __construct(
        private readonly AiGraderAiProvider $provider,
        private readonly AiGraderPromptBuilder $promptBuilder,
        private readonly AiGraderSanitizer $sanitizer,
    ) {
    }

    public function grade(AiGraderCorrectionItem $item, AiGraderPromptContext $context): AiGraderResult
    {
        $payload = $this->payload($this->promptBuilder->build($context));

        try {
            $response = $this->request()->post($this->provider->endpointUrl(), $payload);
        } catch (DecryptException $exception) {
            return AiGraderResult::rejected('provider_token_invalid', [
                'provider' => AiGraderAiProvider::TYPE_LM_STUDIO,
                'ai_provider_id' => $this->provider->id,
                'message' => 'Provider AI token inválido ou incompatível. Reconfigure o provider.',
            ]);
        } catch (Throwable $exception) {
            return AiGraderResult::rejected('connection_error', [
                'provider' => AiGraderAiProvider::TYPE_LM_STUDIO,
                'ai_provider_id' => $this->provider->id,
                'message' => $this->sanitizer->sanitizeString($exception->getMessage()),
            ]);
        }

        return $this->resultFromResponse($response, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function testConnection(): array
    {
        try {
            $response = $this->request()->post($this->provider->endpointUrl(), $this->payload(
                'Retorne somente este JSON: {"ok":true,"message":"pong"}'
            ));
        } catch (DecryptException $exception) {
            return [
                'ok' => false,
                'message' => 'Provider AI token inválido ou incompatível. Reconfigure o provider.',
                'status' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => mb_substr($this->sanitizer->sanitizeString($exception->getMessage()), 0, 500),
                'status' => null,
            ];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'message' => $this->failureMessage($response),
                'status' => $response->status(),
            ];
        }

        $content = $this->contentFromResponse($response);

        return [
            'ok' => trim($content) !== '',
            'message' => trim($content) !== '' ? 'ok' : 'empty_response',
            'status' => $response->status(),
        ];
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds());

        $token = $this->apiKey();

        return $token !== '' ? $request->withToken($token) : $request;
    }

    private function apiKey(): string
    {
        return trim((string) $this->provider->api_key_encrypted);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $prompt): array
    {
        return array_filter([
            'model' => (string) $this->provider->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Voce e um avaliador academico. Responda somente JSON valido, sem markdown.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => $this->provider->temperature !== null ? (float) $this->provider->temperature : null,
            'max_tokens' => $this->provider->max_tokens !== null ? (int) $this->provider->max_tokens : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function resultFromResponse(Response $response, AiGraderPromptContext $context): AiGraderResult
    {
        $rawResponse = $this->rawResponse($response);

        if (! $response->successful()) {
            return AiGraderResult::rejected('http_error', $rawResponse);
        }

        $content = $this->contentFromResponse($response);
        $data = $this->extractJson($content);

        if ($data === null) {
            return AiGraderResult::rejected('parse_error', $rawResponse + ['content' => $this->sanitizer->sanitizeString($content)]);
        }

        $rubricFeedback = $this->rubricFeedback($data['rubric_feedback'] ?? [], $context);
        $rubricPercentage = $this->numericOrNull($data['rubric_percentage'] ?? null)
            ?? $this->overallRubricPercentage($rubricFeedback);
        $score = $this->numericOrNull($data['score'] ?? null);

        if ($rubricFeedback !== [] && $rubricPercentage !== null) {
            $score = ($context->pointsPossible * $rubricPercentage) / 100;
        } elseif ($score === null && $rubricPercentage !== null) {
            $score = ($context->pointsPossible * $rubricPercentage) / 100;
        }

        if ($score === null) {
            return AiGraderResult::rejected('missing_score', $rawResponse + ['parsed' => $this->sanitizer->sanitizeArray($data)]);
        }

        $feedback = trim((string) ($data['general_feedback'] ?? $data['feedback'] ?? ''));

        if ($feedback === '' && $rubricFeedback !== []) {
            $feedback = $this->feedbackFromRubricFeedback($rubricFeedback);
        }

        if ($feedback === '') {
            return AiGraderResult::rejected('missing_feedback', $rawResponse + ['parsed' => $this->sanitizer->sanitizeArray($data)]);
        }

        return new AiGraderResult(
            score: $score,
            feedback: $feedback,
            rubricPercentage: $rubricPercentage,
            rubricFeedback: $rubricFeedback,
            confidence: isset($data['confidence']) ? (string) $data['confidence'] : null,
            reviewFlags: $this->reviewFlags($data['review_flags'] ?? []),
            rawResponse: $rawResponse + [
                'parsed' => $this->sanitizer->sanitizeArray($data),
            ],
            inputTokens: $this->usageValue($response, 'prompt_tokens'),
            outputTokens: $this->usageValue($response, 'completion_tokens'),
            totalTokens: $this->usageValue($response, 'total_tokens'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rawResponse(Response $response): array
    {
        return [
            'provider' => AiGraderAiProvider::TYPE_LM_STUDIO,
            'ai_provider_id' => $this->provider->id,
            'model' => $this->provider->model,
            'endpoint' => $this->provider->endpointUrl(),
            'status' => $response->status(),
            'body' => $this->sanitizer->sanitizeArray($this->responseBody($response)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function responseBody(Response $response): array
    {
        $body = $response->json();

        return is_array($body) ? $body : ['raw_body' => $response->body()];
    }

    private function contentFromResponse(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            $content = data_get($json, 'choices.0.message.content');

            if (is_string($content)) {
                return $content;
            }
        }

        return $response->body();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(string $content): ?array
    {
        $content = trim($content);
        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $content, $matches) !== 1) {
            return null;
        }

        $decoded = json_decode($matches[0], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param mixed $flags
     * @return array<string, mixed>
     */
    private function reviewFlags(mixed $flags): array
    {
        if (! is_array($flags)) {
            return [];
        }

        return $flags;
    }

    /**
     * @param mixed $items
     * @return array<int, array<string, mixed>>
     */
    private function rubricFeedback(mixed $items, AiGraderPromptContext $context): array
    {
        if (! is_array($items)) {
            return [];
        }

        $criteria = $this->criteriaLookup($context->rubricSnapshot['criteria'] ?? []);
        $feedback = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $criterionId = trim((string) ($item['criterion_id'] ?? ''));
            $criterionName = trim((string) ($item['criterion_name'] ?? $item['criterion'] ?? ''));
            $selectedRating = $this->stringOrNull(
                $item['selected_rating']
                ?? $item['rating']
                ?? $item['domain']
                ?? $item['attribute']
                ?? null
            );
            $lookup = $criterionId !== '' ? ($criteria[$criterionId] ?? null) : null;

            if ($lookup === null && $criterionName !== '') {
                $lookup = collect($criteria)->first(function (array $criterion) use ($criterionName): bool {
                    return AiGraderLtiView::normalizeRubricLabel((string) ($criterion['name'] ?? ''))
                        === AiGraderLtiView::normalizeRubricLabel($criterionName);
                });
            }

            $percentage = $this->numericOrNull($item['percentage'] ?? null);
            $maxPercentage = $this->numericOrNull($item['max_percentage'] ?? null)
                ?? $this->numericOrNull($lookup['max_percentage'] ?? null);
            $maxPoints = $this->numericOrNull($item['max_points'] ?? null)
                ?? $this->numericOrNull($lookup['max_points'] ?? null);
            $matchedRating = AiGraderLtiView::findMatchingRubricRating(
                is_array($lookup['ratings'] ?? null) ? $lookup['ratings'] : [],
                $selectedRating,
                $percentage,
            );
            $resolvedPercentage = $this->numericOrNull($matchedRating['percentage'] ?? null) ?? $percentage;
            $resolvedRating = $selectedRating
                ?? $this->stringOrNull($matchedRating['description'] ?? null)
                ?? $this->stringOrNull($matchedRating['id'] ?? null);

            $feedback[] = array_filter([
                'criterion_id' => $criterionId !== '' ? $criterionId : ($lookup['id'] ?? null),
                'criterion_name' => $criterionName !== '' ? $criterionName : ($lookup['name'] ?? null),
                'selected_rating' => $resolvedRating,
                'percentage' => $resolvedPercentage,
                'max_percentage' => $maxPercentage,
                'max_points' => $maxPoints,
                'proportional_score' => $this->numericOrNull($item['proportional_score'] ?? null)
                    ?? AiGraderLtiView::criterionProportionalScore($resolvedPercentage, $context->pointsPossible),
                'feedback' => trim((string) ($item['feedback'] ?? '')),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return array_values($feedback);
    }

    /**
     * @param mixed $criteria
     * @return array<string, array<string, mixed>>
     */
    private function criteriaLookup(mixed $criteria): array
    {
        if (! is_array($criteria)) {
            return [];
        }

        $lookup = [];

        foreach ($criteria as $criterion) {
            if (! is_array($criterion)) {
                continue;
            }

            $id = trim((string) ($criterion['id'] ?? ''));

            if ($id === '') {
                continue;
            }

            $lookup[$id] = $criterion;
        }

        return $lookup;
    }

    /**
     * @param array<int, array<string, mixed>> $rubricFeedback
     */
    private function overallRubricPercentage(array $rubricFeedback): ?float
    {
        $weighted = 0.0;
        $hasWeight = false;

        foreach ($rubricFeedback as $item) {
            $percentage = $this->numericOrNull($item['percentage'] ?? null);
            $maxPercentage = $this->numericOrNull($item['max_percentage'] ?? null);

            if ($percentage === null || $maxPercentage === null) {
                continue;
            }

            $hasWeight = true;
            $weighted += ($percentage * $maxPercentage) / 100;
        }

        return $hasWeight ? round($weighted, 2) : null;
    }

    /**
     * @param array<int, array<string, mixed>> $rubricFeedback
     */
    private function feedbackFromRubricFeedback(array $rubricFeedback): string
    {
        $lines = [];

        foreach ($rubricFeedback as $item) {
            $name = trim((string) ($item['criterion_name'] ?? $item['criterion_id'] ?? 'Critério'));
            $feedback = trim((string) ($item['feedback'] ?? ''));

            if ($feedback === '') {
                continue;
            }

            $lines[] = $name . ': ' . $feedback;
        }

        return implode("\n", $lines);
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function usageValue(Response $response, string $key): ?int
    {
        $value = data_get($response->json(), 'usage.' . $key);

        return is_numeric($value) ? (int) $value : null;
    }

    private function failureMessage(Response $response): string
    {
        $body = $this->responseBody($response);
        $message = data_get($body, 'message') ?: data_get($body, 'error') ?: $response->body();

        return mb_substr($this->sanitizer->sanitizeString('[HTTP ' . $response->status() . '] ' . (string) $message), 0, 500);
    }

    private function timeoutSeconds(): int
    {
        return max(5, min(600, (int) ($this->provider->request_timeout_seconds ?: 300)));
    }
}
