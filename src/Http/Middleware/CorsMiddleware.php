<?php
namespace App\Http\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;

class CorsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        // Tangani preflight di sini juga, kalau belum ada route OPTIONS
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $res = new SlimResponse(204);
            return $this->withCors($request, $res);
        }

        $response = $handler->handle($request);
        return $this->withCors($request, $response);
    }

    private function withCors(Request $request, Response $response): Response
    {
        $origin = $request->getHeaderLine('Origin') ?: '*';
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Vary', 'Origin');
    }
}
