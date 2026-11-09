<?php

declare(strict_types=1);

namespace App\Action\Dashboard;

use App\Dashboard\MessageFilter;
use App\Dashboard\Session;
use App\Dashboard\View;
use App\Mailpit\Message;
use App\Persistence\FileStore;
use App\Persistence\MessageRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Stream;

/**
 * GET /v1/app/messages                          tous les mails, 50 par page, filtrables (voir MessageFilter)
 * GET /v1/app/messages/{id}                     un mail, en onglets (?tab=) : mail, attachments, headers
 * GET /v1/app/messages/{id}/html                corps HTML du mail, affiché dans un cadre isolé (iframe)
 * GET /v1/app/messages/{id}/parts/{partId}      téléchargement d'une pièce jointe
 * POST /v1/app/messages/delete                  supprime les mails cochés dans la liste (champ ids[])
 * POST /v1/app/messages/{id}/delete             supprime un mail
 *
 * Une suppression efface le mail de la base (pièces et destinataires compris) et ses fichiers.
 * Le mail reste dans Mailpit. La confirmation est demandée dans le navigateur (dashboard.js).
 */
final readonly class MessagesController
{
    public const int PER_PAGE = 50;

    /** Onglets de la page d'un mail (?tab=…). Le premier est celui par défaut. */
    public const array TABS = ['mail', 'attachments', 'headers'];

    /** Nombre maximal de mails supprimés en une fois. */
    public const int MAX_DELETE = 500;

    public function __construct(
        private MessageRepository $messages,
        private FileStore $files,
        private Session $session,
        private View $view,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $filter = MessageFilter::fromQuery($request->getQueryParams());

        $result = $this->messages->list(
            self::PER_PAGE,
            ($filter->page - 1) * self::PER_PAGE,
            $filter->addresses === [] ? null : $filter->addresses,
            oldestFirst: $filter->oldestFirst,
        );

        // Une adresse sélectionnée qui n'a plus de mail reste proposée (cochée, à 0).
        $recipients = $this->messages->recipients() + array_fill_keys($filter->addresses, 0);
        ksort($recipients);

        return $this->view->render('messages', [
            'messages'   => $result['messages'],
            'total'      => $result['total'],
            'pages'      => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'perPage'    => self::PER_PAGE,
            'filter'     => $filter,
            'recipients' => $recipients,
        ], title: 'Messages');
    }

    /** @param array{id: string} $args */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $message = $this->message($request, $args['id']);
        $query = $request->getQueryParams();

        $tab = in_array($query['tab'] ?? null, self::TABS, true) ? $query['tab'] : self::TABS[0];

        // Onglet Mail : version texte par défaut ; la version HTML reste accessible (?view=html).
        $hasText = is_string($message['text']) && $message['text'] !== '';
        $hasHtml = is_string($message['html']) && $message['html'] !== '';
        $view = match (true) {
            $hasHtml && (($query['view'] ?? '') === 'html' || !$hasText) => 'html',
            $hasText                                                     => 'text',
            default                                                      => 'none',
        };

        return $this->view->render('message', [
            'message'      => $message,
            'filter'       => MessageFilter::fromQuery($query),
            'tab'          => $tab,
            'content'      => $view,
            'hasText'      => $hasText,
            'hasHtml'      => $hasHtml,
            'remoteImages' => ($query['images'] ?? '') === '1',
        ], title: $message['subject'] !== '' ? $message['subject'] : '(sans sujet)');
    }

    /**
     * Corps HTML, servi à part pour être affiché dans une iframe : la politique de sécurité
     * (CSP « sandbox ») y interdit tout script, formulaire et contenu externe. Les images
     * intégrées (cid:…) y sont incluses directement ; les images distantes ne sont chargées
     * qu'avec ?images=1 (elles permettent à l'expéditeur de savoir que le mail a été ouvert).
     *
     * @param array{id: string} $args
     */
    public function html(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $message = $this->message($request, $args['id']);
        if (!is_string($message['html']) || $message['html'] === '') {
            throw new HttpNotFoundException($request, 'Ce mail n\'a pas de version HTML.');
        }

        $html = $this->embedInlineImages($message['html'], $message['parts']);
        // Les liens s'ouvrent dans un nouvel onglet, hors du cadre isolé.
        $base = '<base target="_blank">';
        $html = preg_match('/<head\b[^>]*>/i', $html) === 1
            ? (string) preg_replace('/<head\b[^>]*>/i', '$0' . $base, $html, 1)
            : $base . $html;

        $images = ($request->getQueryParams()['images'] ?? '') === '1' ? 'data: https: http:' : 'data:';
        $response->getBody()->write($html);

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Content-Security-Policy', implode('; ', [
                'sandbox allow-popups allow-popups-to-escape-sandbox',
                "default-src 'none'",
                "img-src $images",
                "style-src 'unsafe-inline'" . ($images !== 'data:' ? ' https:' : ''),
                'font-src data:' . ($images !== 'data:' ? ' https:' : ''),
                "base-uri 'none'",
                "form-action 'none'",
                "frame-ancestors 'self'",
            ]))
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /** @param array{id: string, partId: string} $args */
    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $message = $this->message($request, $args['id']);

        $part = null;
        foreach ($message['parts'] as $candidate) {
            if ($candidate['part_id'] === $args['partId'] && $candidate['status'] === 'stored') {
                $part = $candidate;
            }
        }
        $file = $part === null ? null : $this->files->open($part['storage_path']);
        if ($file === null) {
            throw new HttpNotFoundException($request, 'Pièce jointe indisponible.');
        }

        $name = $part['file_name'] !== '' ? $part['file_name'] : 'piece-' . $part['part_id'];
        // Repli ASCII pour les vieux navigateurs, nom complet en UTF-8 (RFC 6266).
        $ascii = (string) preg_replace('/[^A-Za-z0-9._ -]/u', '_', $name);
        $type = preg_match('#^[A-Za-z0-9!\#$&^_.+-]+/[A-Za-z0-9!\#$&^_.+-]+$#', $part['content_type']) === 1
            ? strtolower($part['content_type'])
            : 'application/octet-stream';

        return $response
            ->withBody(new Stream($file['handle']))
            ->withHeader('Content-Type', $type)
            ->withHeader('Content-Length', (string) $file['size'])
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($name)))
            ->withHeader('Content-Security-Policy', "sandbox; default-src 'none'")
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function deleteSelected(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $back = $this->backTo($body);

        $ids = $body['ids'] ?? [];
        $ids = array_values(array_unique(array_filter(
            is_array($ids) ? $ids : [],
            static fn (mixed $id): bool => is_string($id) && preg_match(Message::ID_PATTERN, $id) === 1,
        )));
        if ($ids === []) {
            return $this->back($back, 'error', 'Aucun mail sélectionné.');
        }
        if (count($ids) > self::MAX_DELETE) {
            return $this->back($back, 'error', sprintf('%d mails au maximum par suppression.', self::MAX_DELETE));
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if ($this->deleteMessage($id)) {
                $deleted++;
            }
        }

        return $this->back($back, 'success', match ($deleted) {
            0       => 'Aucun mail supprimé : ils l\'avaient déjà été.',
            1       => '1 mail supprimé.',
            default => sprintf('%d mails supprimés.', $deleted),
        });
    }

    /** @param array{id: string} $args */
    public function deleteOne(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $back = $this->backTo((array) $request->getParsedBody());
        if (preg_match(Message::ID_PATTERN, $args['id']) !== 1 || !$this->deleteMessage($args['id'])) {
            return $this->back($back, 'error', 'Ce mail n\'existe plus.');
        }

        return $this->back($back, 'success', '1 mail supprimé.');
    }

    /** Supprime un mail de la base, puis ses fichiers. false s'il n'existait pas. */
    private function deleteMessage(string $id): bool
    {
        if (!$this->messages->delete($id)) {
            return false;
        }
        if (!$this->files->deleteMessage($id)) {
            error_log(sprintf('[delete] fichiers de %s pas tous effacés', $id));
        }

        return true;
    }

    /**
     * Liste à retrouver après la suppression : les filtres sont relus dans le champ « back »
     * (chaîne de requête de la liste), puis réécrits : rien d'autre ne peut passer dans l'URL.
     *
     * @param array<string, mixed> $body
     */
    private function backTo(array $body): string
    {
        $back = is_string($body['back'] ?? null) ? ltrim($body['back'], '?') : '';
        parse_str(substr($back, 0, 8192), $query);

        return '/v1/app/messages' . MessageFilter::fromQuery($query)->query();
    }

    private function back(string $path, string $type, string $message): ResponseInterface
    {
        $this->session->flash('message', ['type' => $type, 'message' => $message]);

        return $this->view->redirect($path);
    }

    /** @return array<string, mixed> */
    private function message(ServerRequestInterface $request, string $id): array
    {
        $message = preg_match(Message::ID_PATTERN, $id) === 1 ? $this->messages->find($id) : null;
        if ($message === null) {
            throw new HttpNotFoundException($request, 'Mail inconnu.');
        }

        return $message;
    }

    /**
     * Remplace les références « cid:… » du HTML par le contenu des images intégrées (data:…).
     *
     * @param list<array<string, mixed>> $parts
     */
    private function embedInlineImages(string $html, array $parts): string
    {
        $replacements = [];
        foreach ($parts as $part) {
            $contentId = trim((string) $part['content_id'], '<> ');
            if ($contentId === '' || $part['status'] !== 'stored' || !str_starts_with(strtolower((string) $part['content_type']), 'image/')) {
                continue;
            }
            $file = $this->files->open($part['storage_path']);
            if ($file === null) {
                continue;
            }
            $data = stream_get_contents($file['handle']);
            fclose($file['handle']);
            if ($data === false) {
                continue;
            }
            $type = preg_match('#^image/[A-Za-z0-9.+-]+$#', strtolower((string) $part['content_type'])) === 1 ? strtolower((string) $part['content_type']) : 'image/png';
            $replacements['cid:' . $contentId] = 'data:' . $type . ';base64,' . base64_encode($data);
        }

        if ($replacements === []) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/cid:([^"\'\s)>]+)/i',
            static fn (array $m): string => $replacements['cid:' . rawurldecode($m[1])] ?? $replacements['cid:' . $m[1]] ?? $m[0],
            $html,
        );
    }
}
