<?php

namespace WHMCS\Module\Server\Gameap;

/**
 * The module's only HTTP surface, and its only call site for logModuleCall().
 *
 * Routing every request through one place is what makes the "no secret ever
 * reaches the module log" rule enforceable rather than aspirational: the
 * bearer token is stripped from the logged request and also handed to
 * logModuleCall() as a replacement variable, so it stays masked even if it
 * turns up inside a response body or an error string.
 */
class HttpClient
{
    private const CONNECT_TIMEOUT = 5;

    /**
     * Creating a game server is a multi-second operation on a busy panel.
     * A short ceiling here is how other WHMCS modules end up with orphaned
     * servers: the panel finishes the work, the module has already given up.
     * Read-only callers that must not hang a page (the client area) pass a
     * shorter value to the constructor.
     */
    public const DEFAULT_TIMEOUT = 30;

    private string $baseUrl;

    private string $token;

    private string $logModule;

    private int $timeout;

    public function __construct(string $baseUrl, string $token, string $logModule = 'gameap', ?int $timeout = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->logModule = $logModule;
        $this->timeout = $timeout !== null && $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,mixed>      $query
     *
     * @return array<mixed>
     */
    public function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . self::buildQuery($query);
        }

        $encodedBody = $body === null ? null : self::encodeBody($body);

        [$status, $response, $curlError] = $this->send($method, $url, $encodedBody);

        $decoded = $response === '' ? [] : json_decode($response, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        $this->log($method, $url, $body, $status, $response, $curlError);

        if ($curlError !== '') {
            throw new ModuleException(
                ModuleException::CODE_TRANSPORT,
                'Could not reach the GameAP panel at ' . $this->baseUrl . ': ' . $curlError
            );
        }

        if ($status >= 400) {
            throw $this->errorFor($status, $method, $path, $decoded);
        }

        return $decoded;
    }

    /**
     * An empty PHP array has no JSON object form: json_encode([]) yields "[]",
     * a list. The panel decodes request bodies into structs, and a list where
     * an object is expected is a type error that it reports as 500, not as a
     * validation failure. An empty body is therefore sent as "{}". Only the
     * top-level empty array is affected: the nested lists the module sends
     * (settings, permissions) are never empty when they are sent at all.
     *
     * @param array<string,mixed> $body
     */
    public static function encodeBody(array $body): string
    {
        if ($body === []) {
            return '{}';
        }

        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new ModuleException(
                ModuleException::CODE_STATE,
                'Failed to encode the request body: ' . json_last_error_msg() . '.'
            );
        }

        return $encoded;
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function send(string $method, string $url, ?string $body): array
    {
        $handle = curl_init();

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'User-Agent: GameAP-WHMCS',
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => $this->timeout,
            // A redirect would replay the request, Authorization header and
            // all, against whatever host the response names. A 3xx here is a
            // misconfiguration, not something to follow.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $curlError = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

        return [$status, is_string($response) ? $response : '', $curlError];
    }

    /**
     * The panel's error envelope is {status, error, message, http_code} with
     * an optional per-field "errors" map for validation failures. The message
     * is summarised into one readable line; the raw detail travels separately
     * on the exception so callers can classify a 403 without parsing prose.
     *
     * @param array<mixed> $decoded
     */
    private function errorFor(int $status, string $method, string $path, array $decoded): ModuleException
    {
        $detail = '';
        foreach (['error', 'message', 'title'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key]) && $decoded[$key] !== '') {
                $detail = $decoded[$key];

                break;
            }
        }

        $fields = [];
        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            foreach ($decoded['errors'] as $field => $messages) {
                $messages = is_array($messages) ? array_filter($messages, 'is_string') : [(string) $messages];
                if ($messages !== []) {
                    $fields[] = $field . ': ' . implode(', ', $messages);
                }
            }
        }

        $code = match (true) {
            $status === 401 => ModuleException::CODE_AUTH,
            $status === 403 => ModuleException::CODE_FORBIDDEN,
            $status === 404 => ModuleException::CODE_NOT_FOUND,
            $status === 409 => ModuleException::CODE_CONFLICT,
            default => ModuleException::CODE_PANEL,
        };

        $message = 'GameAP panel refused ' . $method . ' ' . $path . ' (HTTP ' . $status . ')';
        if ($detail !== '') {
            $message .= ': ' . $detail;
        }

        if ($fields !== []) {
            $message .= ' [' . implode('; ', $fields) . ']';
        }

        $hint = self::hintFor($status, $detail);
        if ($hint !== '') {
            $message .= '. ' . $hint;
        }

        return new ModuleException($code, $message, $status, null, $detail);
    }

    /**
     * The panel answers 403 for unrelated reasons and only the text tells
     * them apart; the hint turns each into the action an administrator can
     * actually take.
     */
    private static function hintFor(int $status, string $detail): string
    {
        if ($status === 401) {
            return 'Check the API token in the server Password (or Access Hash) field.';
        }

        if ($status !== 403) {
            return '';
        }

        $lower = mb_strtolower($detail);

        if (str_contains($lower, 'admin permissions required')) {
            return 'The token belongs to a panel user who is not an administrator; '
                . 'create it from an administrator account.';
        }

        if (str_contains($lower, 'cannot modify administrators')) {
            return 'The panel account is an administrator; personal access tokens cannot manage administrators.';
        }

        if (str_contains($lower, 'cannot change passwords')) {
            return 'Personal access tokens cannot change passwords.';
        }

        if (str_contains($lower, 'abilit')) {
            return 'The token is missing an ability required for this call.';
        }

        return '';
    }

    /**
     * @param array<string,mixed>|null $body
     */
    private function log(
        string $method,
        string $url,
        ?array $body,
        int $status,
        string $response,
        string $curlError
    ): void {
        if (!function_exists('logModuleCall')) {
            return;
        }

        $request = [
            'method' => $method,
            'url' => $url,
            // Headers are deliberately absent: the only interesting one holds
            // the token.
            'body' => $body === null ? null : self::redactBody($body),
        ];

        $result = $curlError !== ''
            ? ['curl_error' => $curlError]
            : ['http_code' => $status, 'response' => $response];

        logModuleCall($this->logModule, $method . ' ' . $url, $request, $result, null, [$this->token]);
    }

    /**
     * Passwords the module generates never need to be readable in a log, and
     * the service credentials are stored in WHMCS anyway.
     *
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    private static function redactBody(array $body): array
    {
        foreach (['password', 'rcon'] as $key) {
            if (array_key_exists($key, $body)) {
                $body[$key] = '***';
            }
        }

        return $body;
    }

    /**
     * @param array<string,mixed> $query
     */
    private static function buildQuery(array $query): string
    {
        $parts = [];

        foreach ($query as $key => $value) {
            foreach ((array) $value as $item) {
                if ($item === null || $item === '') {
                    continue;
                }

                $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $item);
            }
        }

        return implode('&', $parts);
    }
}
