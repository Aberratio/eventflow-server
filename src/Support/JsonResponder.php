<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support;

use Psr\Http\Message\ResponseInterface as Response;

final class JsonResponder
{
    public static function ok(Response $response, mixed $data, ?string $message = null, int $status = 200): Response
    {
        $payload = ['success' => true, 'data' => $data];
        if ($message !== null) {
            $payload['message'] = $message;
        }

        return self::json($response, $payload, $status);
    }

    /**
     * @param array<int, mixed> $data
     */
    public static function paginated(Response $response, array $data, int $total, int $page, int $pageSize): Response
    {
        $totalPages = $pageSize > 0 ? (int) ceil($total / $pageSize) : 1;
        return self::json($response, [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => max(1, $totalPages),
        ]);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(
        Response $response,
        string $code,
        string $message,
        int $status = 400,
        array $details = []
    ): Response {
        $payload = ['success' => false, 'error' => ['code' => $code, 'message' => $message]];
        if ($details !== []) {
            $payload['error']['details'] = $details;
        }

        return self::json($response, $payload, $status);
    }

    /** 403 with the standard "no permission" message unless a more specific one is given. */
    public static function forbidden(Response $response, ?string $message = null): Response
    {
        return self::error($response, 'forbidden', $message ?? 'Nie masz uprawnień do wykonania tej operacji.', 403);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }
}
