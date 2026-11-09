<?php

declare(strict_types=1);

namespace App\Action\Dashboard;

use App\Dashboard\View;
use App\Persistence\MessageRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/app/messages?page=N : tous les mails, du plus récent au plus ancien, 50 par page.
 */
final readonly class MessagesController
{
    public const int PER_PAGE = 50;

    public function __construct(
        private MessageRepository $messages,
        private View $view,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $page = $request->getQueryParams()['page'] ?? '1';
        $page = is_string($page) && ctype_digit($page) && strlen($page) <= 6 ? max(1, (int) $page) : 1;

        $result = $this->messages->list(self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        return $this->view->render('messages', [
            'messages' => $result['messages'],
            'total'    => $result['total'],
            'page'     => $page,
            'pages'    => $pages,
            'perPage'  => self::PER_PAGE,
        ], title: 'Messages');
    }
}
