<?php

namespace App\Infrastructure\Http;

use App\Application\Exceptions\AccessDenied;
use App\Application\Exceptions\ConcurrencyConflict;
use App\Application\Exceptions\InputRejected;
use App\Application\Exceptions\RecordNotFound;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/** Existing service entry points preserve the HTTP exception contracts. */
final class ApplicationErrors
{
    public static function translate(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $error) {
            throw self::toHttp($error);
        }
    }

    public static function toHttp(Throwable $error): Throwable
    {
        return match (true) {
            $error instanceof InputRejected => ValidationException::withMessages($error->errors),
            $error instanceof ConcurrencyConflict => new ConflictHttpException($error->getMessage()),
            $error instanceof AccessDenied => new AccessDeniedHttpException($error->getMessage()),
            $error instanceof RecordNotFound => new NotFoundHttpException,
            default => $error,
        };
    }
}
