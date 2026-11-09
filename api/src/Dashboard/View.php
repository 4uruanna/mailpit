<?php

declare(strict_types=1);

namespace App\Dashboard;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Rendu des pages du Dashboard : un gabarit PHP de templates/, inséré dans templates/layout.php.
 *
 * Variables disponibles dans les gabarits :
 *   $e($valeur)        texte échappé pour le HTML (à utiliser pour TOUT ce qui vient de la base)
 *   $url('/v1/app/…')  URL avec le préfixe base_path
 *   $date($dateTime)   date lisible, dans le fuseau du Dashboard
 *   $csrf              jeton anti-CSRF, à mettre dans chaque formulaire POST (champ « _csrf »)
 *   $admin             identifiant de l'administrateur connecté, ou null
 *   $flash             message à afficher : ['type' => 'success'|'error', 'message' => '…'] ou null
 */
final readonly class View
{
    public function __construct(
        private string $templateDir,
        private string $basePath,
        private DateTimeZone $timezone,
        private Session $session,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function url(string $path): string
    {
        return $this->basePath . $path;
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = [], int $status = 200, ?string $title = null): ResponseInterface
    {
        $helpers = [
            'e'     => static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'url'   => $this->url(...),
            'date'  => $this->date(...),
            'csrf'  => $this->session->csrfToken(),
            'admin' => $this->session->username(),
            'flash' => $vars['flash'] ?? $this->session->takeFlash('message'),
        ];

        $content = $this->include($template, $vars + $helpers);
        $html = $this->include('layout', ['content' => $content, 'title' => $title, 'page' => $template] + $helpers);

        $response = $this->responseFactory->createResponse($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
        $response->getBody()->write($html);

        return $response;
    }

    /** Redirection après un formulaire (303 : le navigateur refait un GET). */
    public function redirect(string $path): ResponseInterface
    {
        return $this->responseFactory->createResponse(303)->withHeader('Location', $this->url($path));
    }

    public function date(?DateTimeInterface $date): string
    {
        if ($date === null) {
            return '—';
        }

        return DateTimeImmutable::createFromInterface($date)->setTimezone($this->timezone)->format('d/m/Y H:i:s');
    }

    /** @param array<string, mixed> $vars */
    private function include(string $template, array $vars): string
    {
        $file = $this->templateDir . '/' . $template . '.php';
        if (!preg_match('/^[a-z-]+$/', $template) || !is_file($file)) {
            throw new RuntimeException(sprintf('gabarit introuvable : %s', $template));
        }

        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })($file, $vars);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
