<?php

declare(strict_types=1);

namespace App\Http;

use Slim\Exception\HttpException;
use Slim\Handlers\ErrorHandler as SlimErrorHandler;

/**
 * Gestionnaire d'erreurs Slim qui ne logue que les vraies erreurs :
 * les 404 / 405 (scans, robots…) ne remplissent pas le fichier de log.
 */
final class ErrorHandler extends SlimErrorHandler
{
    protected function writeToErrorLog(): void
    {
        if ($this->exception instanceof HttpException) {
            return;
        }

        parent::writeToErrorLog();
    }
}
