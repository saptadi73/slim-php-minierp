<?php
namespace App\Http\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;
use App\Models\User;

class RoleMiddleware implements MiddlewareInterface
{
    public function __construct(private array $roles) {}

    public function process(Request $request, Handler $handler): Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('auth_user');
        if (!$user) return $this->forbidden('Unauthenticated');
        foreach ($this->roles as $role) {
            if ($user->hasRole($role)) return $handler->handle($request);
        }
        return $this->forbidden('Forbidden: insufficient role');
    }

    private function forbidden(string $msg): Response
    {
        $res = new SlimResponse();
        $res->getBody()->write(json_encode(['message'=>$msg]));
        return $res->withStatus(403)->withHeader('Content-Type','application/json');
    }
}
