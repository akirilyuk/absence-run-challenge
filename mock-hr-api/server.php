<?php

declare(strict_types=1);

/**
 * Mock HR API for the Absence Run challenge.
 *
 * Run it with PHP's built-in server (no Docker required):
 *
 *     php -S 127.0.0.1:8081 mock-hr-api/server.php
 *
 * Endpoints:
 *   POST /v1/leave-decisions   record a decision (Bearer auth + Idempotency-Key required)
 *   GET  /v1/leave-decisions   list everything recorded so far
 *   POST /v1/_reset            wipe recorded state (handy between runs)
 *
 * State is persisted to mock-hr-api/state.json so idempotency survives across
 * requests (each request to the built-in server is a fresh process).
 */
$token = getenv('HR_API_TOKEN') ?: 'demo-secret-token-7Qx2';
$stateFile = __DIR__.'/state.json';

$send = static function (int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    exit;
};

// --- Authentication --------------------------------------------------------
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authHeader !== 'Bearer '.$token) {
    $send(401, ['error' => 'unauthorized', 'detail' => 'Missing or invalid bearer token.']);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

/** @var array{decisions: array<string, mixed>, idempotency: array<string, string>} $state */
$state = is_file($stateFile)
    ? (json_decode((string) file_get_contents($stateFile), true) ?: ['decisions' => [], 'idempotency' => []])
    : ['decisions' => [], 'idempotency' => []];

// --- Routing ---------------------------------------------------------------
if ('POST' === $method && '/v1/_reset' === $path) {
    @unlink($stateFile);
    $send(200, ['ok' => true]);
}

if ('GET' === $method && '/v1/leave-decisions' === $path) {
    $send(200, ['decisions' => array_values($state['decisions'])]);
}

if ('POST' === $method && '/v1/leave-decisions' === $path) {
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
    if ('' === $key) {
        $send(400, ['error' => 'missing_idempotency_key', 'detail' => 'Send an Idempotency-Key header.']);
    }

    // Replay the original result for a key we have already seen.
    if (isset($state['idempotency'][$key])) {
        $existingId = $state['idempotency'][$key];
        $send(200, ['id' => $existingId, 'replayed' => true, 'record' => $state['decisions'][$existingId]]);
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $send(400, ['error' => 'invalid_json']);
    }

    $id = 'dec_'.bin2hex(random_bytes(6));
    $state['decisions'][$id] = ['id' => $id, 'receivedAt' => date('c'), 'payload' => $payload];
    $state['idempotency'][$key] = $id;
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $send(201, ['id' => $id, 'replayed' => false]);
}

$send(404, ['error' => 'not_found', 'detail' => sprintf('%s %s', $method, $path)]);
