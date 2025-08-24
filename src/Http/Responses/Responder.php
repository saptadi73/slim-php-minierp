<?php
namespace App\Http\Responses;

use Psr\Http\Message\ResponseInterface as Response;

final class Responder
{
    public function __construct(private bool $debug = false) {}

    public function success(Response $res, mixed $data = null, int $status = 200, array $meta = [], array $links = []): Response
    {
        $payload = [
            'success' => true,
            'data'    => $data,
            'meta'    => (object) $meta,
            'links'   => (object) $links,
        ];
        return $this->json($res, $payload, $status);
    }

    public function paginated(Response $res, $items, $paginator, int $status = 200): Response
    {
        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'last_page'    => $paginator->lastPage(),
            'from'         => $paginator->firstItem(),
            'to'           => $paginator->lastItem(),
        ];
        $links = [
            'first' => $paginator->url(1),
            'last'  => $paginator->url($paginator->lastPage()),
            'prev'  => $paginator->previousPageUrl(),
            'next'  => $paginator->nextPageUrl(),
        ];
        return $this->success($res, $items, $status, $meta, $links);
    }

    public function error(Response $res, string $code, string $message, int $status = 400, array $details = [], ?\Throwable $e = null): Response
    {
        $err = ['code' => $code, 'message' => $message];
        if ($details) $err['details'] = $details;
        if ($this->debug && $e) $err['trace'] = $e->getTraceAsString();

        $payload = ['success' => false, 'error' => $err];
        return $this->json($res, $payload, $status);
    }

    private function json(Response $res, array $payload, int $status): Response
    {
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
