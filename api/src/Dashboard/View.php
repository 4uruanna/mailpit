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
 *   $date($dateTime)   date lisible, dans le fuseau du Dashboard (objet date ou texte ISO 8601)
 *   $bytes($taille)    taille lisible (« 12,4 Ko »)
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
            'bytes' => self::bytes(...),
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

    /** Script statique de templates/ (ex. dashboard.js). */
    public function script(string $name): ResponseInterface
    {
        $file = $this->templateDir . '/' . $name;
        if (!preg_match('/^[a-z-]+\.js$/', $name) || !is_file($file)) {
            throw new RuntimeException(sprintf('script introuvable : %s', $name));
        }

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/javascript; charset=utf-8')
            ->withHeader('Cache-Control', 'private, max-age=300');
        $response->getBody()->write((string) file_get_contents($file));

        return $response;
    }

    /** Redirection après un formulaire (303 : le navigateur refait un GET). */
    public function redirect(string $path): ResponseInterface
    {
        return $this->responseFactory->createResponse(303)->withHeader('Location', $this->url($path));
    }

    /** @param DateTimeInterface|string|null $date objet date, ou date ISO 8601 (« 2026-11-09T08:31:20.474Z ») */
    public function date(DateTimeInterface|string|null $date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }

        $date = is_string($date) ? new DateTimeImmutable($date) : DateTimeImmutable::createFromInterface($date);

        return $date->setTimezone($this->timezone)->format('d/m/Y H:i:s');
    }

    /** Taille lisible : « 512 o », « 12,4 Ko », « 3,1 Mo ». */
    public static function bytes(int $size): string
    {
        return match (true) {
            $size < 1024        => $size . ' o',
            $size < 1024 ** 2   => number_format($size / 1024, 1, ',', ' ') . ' Ko',
            default             => number_format($size / 1024 ** 2, 1, ',', ' ') . ' Mo',
        };
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
