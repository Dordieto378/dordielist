<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class TmdbListService
{
    private const API_URL = 'https://api.themoviedb.org/3';

    public const LIST_IDS = [
        'COMPLETED' => 8697654,
        'CURRENT' => 8697811,
        'PLANNING' => 8697812,
        'PAUSED' => 8697813,
        'DROPPED' => 8697814,
        'REPEATING' => 8697815,
    ];

    public function createRequestToken(string $apiToken): string
    {
        $response = $this->client($apiToken)
            ->get(self::API_URL.'/authentication/token/new');

        $this->ensureSuccessful($response, 'TMDb account connection failed.');

        $requestToken = $response->json('request_token');
        if (! is_string($requestToken) || $requestToken === '') {
            throw new RuntimeException('TMDb did not return an account request token.');
        }

        return $requestToken;
    }

    public function authorizationUrl(string $requestToken, string $callbackUrl): string
    {
        return 'https://www.themoviedb.org/authenticate/'.rawurlencode($requestToken)
            .'?'.http_build_query(['redirect_to' => $callbackUrl]);
    }

    public function createSession(string $apiToken, string $requestToken): string
    {
        $response = $this->client($apiToken)
            ->post(self::API_URL.'/authentication/session/new', [
                'request_token' => $requestToken,
            ]);

        $this->ensureSuccessful($response, 'TMDb account authorization failed.');

        $sessionId = $response->json('session_id');
        if (! is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('TMDb did not return an account session.');
        }

        return $sessionId;
    }

    public function deleteSession(string $apiToken, string $sessionId): void
    {
        $response = $this->client($apiToken)
            ->delete(self::API_URL.'/authentication/session', [
                'session_id' => $sessionId,
            ]);

        $this->ensureSuccessful($response, 'TMDb account disconnect failed.');
    }

    /**
     * @return array<int, string> TMDb movie ID => Dordielist status
     */
    public function configuredListEntries(string $apiToken, ?string $sessionId = null): array
    {
        $entries = [];

        foreach (self::LIST_IDS as $status => $listId) {
            $page = 1;

            do {
                $query = [
                    'language' => 'en-US',
                    'page' => $page,
                ];

                if ($sessionId) {
                    $query['session_id'] = $sessionId;
                }

                $response = $this->client($apiToken)
                    ->get(self::API_URL."/list/{$listId}", $query);

                $this->ensureSuccessful($response, "TMDb list {$listId} could not be read.");

                foreach ((array) $response->json('items', []) as $item) {
                    if (($item['media_type'] ?? 'movie') !== 'movie') {
                        continue;
                    }

                    $movieId = (int) ($item['id'] ?? 0);
                    if ($movieId > 0 && ! isset($entries[$movieId])) {
                        $entries[$movieId] = $status;
                    }
                }

                $totalPages = max(1, min(500, (int) $response->json('total_pages', 1)));
                $page++;
            } while ($page <= $totalPages);
        }

        return $entries;
    }

    public function moveMovie(
        int $movieId,
        ?string $previousStatus,
        string $newStatus,
        string $apiToken,
        string $sessionId
    ): void {
        $destinationListId = self::LIST_IDS[$newStatus] ?? null;
        if (! $destinationListId) {
            throw new InvalidArgumentException('The selected movie list is not mapped to TMDb.');
        }

        if ($previousStatus === $newStatus) {
            return;
        }

        $addResponse = $this->client($apiToken)
            ->withQueryParameters(['session_id' => $sessionId])
            ->post(self::API_URL."/list/{$destinationListId}/add_item", [
                'media_id' => $movieId,
            ]);

        $this->ensureListMutation($addResponse, [1, 8, 12], 'TMDb could not add the movie to its new list.');

        $previousListId = self::LIST_IDS[$previousStatus] ?? null;
        if (! $previousListId || $previousListId === $destinationListId) {
            return;
        }

        $removeResponse = $this->client($apiToken)
            ->withQueryParameters(['session_id' => $sessionId])
            ->post(self::API_URL."/list/{$previousListId}/remove_item", [
                'media_id' => $movieId,
            ]);

        $this->ensureListMutation($removeResponse, [1, 13, 21], 'TMDb could not remove the movie from its previous list.');
    }

    private function client(string $apiToken)
    {
        return Http::withToken($apiToken)
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    private function ensureListMutation(Response $response, array $allowedStatusCodes, string $fallback): void
    {
        $statusCode = (int) $response->json('status_code', 0);

        if ($response->successful() || in_array($statusCode, $allowedStatusCodes, true)) {
            return;
        }

        throw new RuntimeException($this->errorMessage($response, $fallback));
    }

    private function ensureSuccessful(Response $response, string $fallback): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException($this->errorMessage($response, $fallback));
    }

    private function errorMessage(Response $response, string $fallback): string
    {
        $message = trim((string) $response->json('status_message'));

        return $message !== '' ? $message : $fallback;
    }
}
