<?php
namespace App\Http\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;
use App\Services\AuthService;
use App\Models\User;

class JwtMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthService $auth) {}

    public function process(Request $request, Handler $handler): Response
    {
        $hdr = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $hdr, $m)) {
            return $this->unauthorized('Missing Bearer token');
        }
        $claims = $this->auth->decode($m[1]);
        if (!$claims || empty($claims['sub'])) {
            return $this->unauthorized('Invalid or expired token');
        }
        // optional: fetch user (not deleted)
        $user = User::find($claims['sub']);
        if (!$user) return $this->unauthorized('User not found');

        // inject user to request attribute
        $request = $request->withAttribute('auth_user', $user);
        return $handler->handle($request);
    }

    private function unauthorized(string $msg): Response
    {
        $res = new SlimResponse();
        $res->getBody()->write(json_encode(['message'=>$msg]));
        return $res->withStatus(401)->withHeader('Content-Type','application/json');
    }
}
