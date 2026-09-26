<?php

declare(strict_types=1);

namespace App\Services\Canvas;

use App\Models\CanvasEnvironment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

final class CanvasApiClient
{
    public function __construct(
        private readonly CanvasEnvironment $environment,
    ) {
    }

    public function listAssignments(int|string $courseId): \Illuminate\Support\Collection
    {
        $url = "/api/v1/courses/{$courseId}/assignments?per_page=100";

        $items = [];

        do {
            $response = $this->request()->get($url);

            $this->throwIfFailed($response, 'GET', $url);

            $data = $response->json();

            if (is_array($data)) {
                $items = array_merge($items, $data);
            }

            $url = $this->extractNextPageUrl($response->header('Link'));
        } while ($url !== null);

        return collect($items);
    }

    public function findAssignmentByName(int|string $courseId, string $assignmentName): ?array
    {
        $normalizedTarget = $this->normalizeName($assignmentName);

        $matches = $this->listAssignments($courseId)
            ->filter(function (array $assignment) use ($normalizedTarget): bool {
                $name = (string) ($assignment['name'] ?? '');

                return $this->normalizeName($name) === $normalizedTarget;
            })
            ->values();

        if ($matches->count() > 1) {
            throw new RuntimeException(sprintf(
                'Mais de uma atividade com o nome "%s" foi encontrada no curso %s.',
                $assignmentName,
                $courseId
            ));
        }

        return $matches->first();
    }

    public function findAssignmentByExactName(int|string $courseId, string $assignmentName): ?array
    {
        $target = trim($assignmentName);

        $matches = $this->listAssignments($courseId)
            ->filter(function (array $assignment) use ($target): bool {
                return trim((string) ($assignment['name'] ?? '')) === $target;
            })
            ->values();

        if ($matches->count() > 1) {
            throw new RuntimeException(sprintf(
                'Mais de uma atividade com o nome "%s" foi encontrada no curso %s.',
                $assignmentName,
                $courseId
            ));
        }

        return $matches->first();
    }

    public function listSubmissionsForAssignment(int|string $courseId, int|string $assignmentId): \Illuminate\Support\Collection
    {
        return $this->getPaginated(
            "/api/v1/courses/{$courseId}/assignments/{$assignmentId}/submissions?per_page=100&include[]=user&include[]=submission_history"
        );
    }

    /**
     * @param array<string, string|null> $dates
     * @return array<string, mixed>
     */
    public function updateAssignmentDates(
        int|string $courseId,
        int|string $assignmentId,
        array $dates,
    ): array {
        $payload = array_filter([
            'assignment[due_at]' => $dates['due_at'] ?? null,
            'assignment[unlock_at]' => $dates['unlock_at'] ?? null,
            'assignment[lock_at]' => $dates['lock_at'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        if ($payload === []) {
            throw new RuntimeException('Nenhuma data válida foi informada para atualização.');
        }

        $response = $this->request()
            ->asForm()
            ->put("/api/v1/courses/{$courseId}/assignments/{$assignmentId}", $payload);


        $this->throwIfFailed($response, 'PUT', "/api/v1/courses/{$courseId}/assignments/{$assignmentId}");

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('Canvas API: resposta inválida ao atualizar atividade.');
        }

        return $data;
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return \Illuminate\Support\Facades\Http::baseUrl(rtrim($this->environment->base_url, '/'))
            ->acceptJson()
            ->withToken($this->environment->api_token)
            ->timeout(60)
            ->retry(2, 500);
    }

    private function throwIfFailed(Response $response, string $method = 'GET', string $endpoint = ''): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $rawBody = trim($response->body());
        $body = $response->json();

        $canvasMessage = null;

        if (is_array($body)) {
            if (isset($body['errors']) && is_array($body['errors'])) {
                $canvasMessage = collect($body['errors'])
                    ->map(function ($error): string {
                        if (is_array($error)) {
                            return (string) ($error['message'] ?? json_encode($error, JSON_UNESCAPED_UNICODE));
                        }

                        return (string) $error;
                    })
                    ->filter()
                    ->implode(' | ');
            }

            if (! $canvasMessage && isset($body['message'])) {
                $canvasMessage = (string) $body['message'];
            }

            if (! $canvasMessage && isset($body['error'])) {
                $canvasMessage = (string) $body['error'];
            }
        }

        if (! $canvasMessage && $rawBody !== '') {
            $canvasMessage = $rawBody;
        }

        $message = match ($status) {
            401 => 'Canvas API: token inválido ou expirado.',
            403 => 'Canvas API: acesso negado para esta operação.',
            404 => 'Canvas API: recurso não encontrado.',
            422 => 'Canvas API: dados inválidos para a operação.',
            429 => 'Canvas API: limite de requisições excedido. Tente novamente em instantes.',
            500, 502, 503, 504 => 'Canvas API: indisponível no momento.',
            default => sprintf('Canvas API error [%s].', $status),
        };

        if ($canvasMessage) {
            $message .= ' ' . $canvasMessage;
        }

        throw new CanvasApiException(
            status: $status,
            method: $method,
            endpoint: $endpoint,
            responseBody: $rawBody,
            environmentId: $this->environment->id,
            message: sprintf('[HTTP %s] %s', $status, $message),
        );
    }

    private function normalizeName(string $value): string
    {
        return mb_strtolower(trim($value));
    }
    private function extractNextPageUrl(?string $linkHeader): ?string
    {
        if (! is_string($linkHeader) || trim($linkHeader) === '') {
            return null;
        }

        $links = explode(',', $linkHeader);

        foreach ($links as $linkPart) {
            $segments = explode(';', trim($linkPart));

            if (count($segments) < 2) {
                continue;
            }

            $urlPart = trim($segments[0]);
            $relPart = trim($segments[1]);

            if ($relPart !== 'rel="next"') {
                continue;
            }

            if (preg_match('/<(.+)>/', $urlPart, $matches) !== 1) {
                continue;
            }

            $absoluteUrl = $matches[1];
            $parsed = parse_url($absoluteUrl);

            if (! is_array($parsed) || empty($parsed['path'])) {
                return null;
            }

            $relativeUrl = $parsed['path'];

            if (! empty($parsed['query'])) {
                $relativeUrl .= '?' . $parsed['query'];
            }

            return $relativeUrl;
        }

        return null;
    }
    /**
     * @return array<string, mixed>
     */
    public function testConnection(): array
    {
        $response = $this->request()->get('/api/v1/users/self');

        $this->throwIfFailed($response, 'GET', '/api/v1/users/self');

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('Canvas API: resposta inválida no teste de conexão.');
        }

        return $data;
    }

    public function getPaginated(string $url, ?int $maxPages = null, ?string $rootKey = null): \Illuminate\Support\Collection
    {
        $items = [];
        $page = 0;

        do {
            $response = $this->request()->get($url);

            $this->throwIfFailed($response, 'GET', $url);

            $data = $response->json();

            if ($rootKey !== null && is_array($data) && array_key_exists($rootKey, $data)) {
                $data = $data[$rootKey];
            }

            if (is_array($data)) {
                $items = array_merge($items, $data);
            }

            $page++;
            $url = $this->extractNextPageUrl($response->header('Link'));
        } while ($url !== null && ($maxPages === null || $page < $maxPages));

        return collect($items);
    }
    /**
     * @return array<string, mixed>
     */
    public function getCourse(int|string $courseId): array
    {
        $response = $this->request()->get("/api/v1/courses/{$courseId}");

        $this->throwIfFailed($response, 'GET', "/api/v1/courses/{$courseId}");

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('Canvas API: resposta inválida ao buscar curso.');
        }

        return $data;
    }
    public function get(string $url, array $query = []): array
    {
        $response = $query === []
            ? $this->request()->get($url)
            : $this->request()->get($url, $query);

        $this->throwIfFailed($response, 'GET', $url);

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    public function post(string $url, array $payload = []): array
    {
        $response = $this->request()->post($url, $payload);

        $this->throwIfFailed($response, 'POST', $url);

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    public function getResponse(string $url, array $query = []): Response
    {
        return $query === []
            ? $this->request()->get($url)
            : $this->request()->get($url, $query);
    }

    public function postResponse(string $url, array $payload = []): Response
    {
        return $this->request()->post($url, $payload);
    }

    public function putResponse(string $url, array $payload = []): Response
    {
        return $this->request()->put($url, $payload);
    }

    public function postFormResponse(string $url, array $payload = []): Response
    {
        return $this->request()
            ->asForm()
            ->post($url, $payload);
    }

    public function postForm(string $url, array $payload = []): array
    {
        $response = $this->request()
            ->asForm()
            ->post($url, $payload);

        $this->throwIfFailed($response, 'POST', $url);

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    public function delete(string $url, array $query = []): array
    {
        $response = $this->request()->delete($url, $query);

        $this->throwIfFailed($response, 'DELETE', $url);

        $data = $response->json();

        return is_array($data) ? $data : [];
    }
    /**
     * @return array<string, mixed>
     */
    public function put(string $endpoint, array $payload = []): array
    {
        $response = $this->request()->put($endpoint, $payload);

        $this->throwIfFailed($response, 'PUT', $endpoint);

        $data = $response->json();

        if (! is_array($data)) {
            return [];
        }

        return $data;
    }

    public function putForm(string $endpoint, array $payload = []): array
    {
        $response = $this->request()
            ->asForm()
            ->put($endpoint, $payload);

        $this->throwIfFailed($response, 'PUT', $endpoint);

        $data = $response->json();

        if (! is_array($data)) {
            return [];
        }

        return $data;
    }

    // /**
    //  * @return array<string, mixed>
    //  */
    // public function post(string $endpoint, array $payload = []): array
    // {
    //     $response = $this->request()->post($endpoint, $payload);

    //     $this->throwIfFailed($response);

    //     $data = $response->json();

    //     if (! is_array($data)) {
    //         return [];
    //     }

    //     return $data;
    // }

    // /**
    //  * @return array<string, mixed>
    //  */
    // public function delete(string $endpoint, array $payload = []): array
    // {
    //     $response = $this->request()->delete($endpoint, $payload);

    //     $this->throwIfFailed($response);

    //     $data = $response->json();

    //     if (! is_array($data)) {
    //         return [];
    //     }

    //     return $data;
    // }
}
