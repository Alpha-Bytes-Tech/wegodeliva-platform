<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every API error as { "error": { "code", "message", "fields" } } (spec 14 §14.1).
 * 500s never leak details to clients; they are logged by the framework's reporter.
 */
final class ApiErrorRenderer
{
    public static function shouldRender(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::shouldRender($request)) {
            return null;
        }

        return match (true) {
            $e instanceof ApiException => $this->respond($e->errorCode, $e->status, $e->fields, $e->meta, $e->headers),
            $e instanceof ValidationException => $this->respond('validation_failed', 400, $this->fields($e)),
            $e instanceof AuthenticationException => $this->respond('unauthenticated', 401),
            $e instanceof AuthorizationException => $this->respond('forbidden', 403),
            $e instanceof ModelNotFoundException => $this->respond('not_found', 404),
            $e instanceof ThrottleRequestsException => $this->respond('rate_limited', 429, headers: $e->getHeaders()),
            $e instanceof HttpExceptionInterface => $this->fromHttpException($e),
            default => $this->respond('server_error', 500),
        };
    }

    private function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        $code = match ($status) {
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            419 => 'unauthenticated',
            426 => 'update_required',
            429 => 'rate_limited',
            503 => 'service_unavailable',
            default => $status >= 500 ? 'server_error' : 'bad_request',
        };

        return $this->respond($code, $status === 419 ? 401 : $status, headers: $e->getHeaders());
    }

    /** @return array<string, list<string>> */
    private function fields(ValidationException $e): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = $e->errors();

        return $errors;
    }

    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    private function respond(string $code, int $status, array $fields = [], array $meta = [], array $headers = []): JsonResponse
    {
        $key = "errors.{$code}";
        $message = __($key);
        if ($message === $key) {
            $message = __('errors.server_error');
        }

        $error = ['code' => $code, 'message' => $message, 'fields' => (object) $fields];
        if ($meta !== []) {
            $error['meta'] = $meta;
        }

        return new JsonResponse(['error' => $error], $status, $headers);
    }
}
