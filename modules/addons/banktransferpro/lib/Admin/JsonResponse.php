<?php

declare(strict_types=1);

namespace BankTransferPro\Admin;

final class JsonResponse
{
    /**
     * @param array<string, mixed>|list<mixed>|null $data
     */
    public static function success(?array $data = null, string $message = ''): never
    {
        self::send([
            'success' => true,
            'data' => $data ?? new \stdClass(),
            'message' => $message,
        ]);
    }

    public static function error(string $code, string $message, int $httpStatus = 400): never
    {
        self::send([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $httpStatus);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function send(array $payload, int $httpStatus = 200): never
    {
        if (! headers_sent()) {
            http_response_code($httpStatus);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode($payload, JSON_THROW_ON_ERROR);
        exit;
    }
}
