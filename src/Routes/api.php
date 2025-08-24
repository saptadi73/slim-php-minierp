<?php
use Slim\App;
use Slim\Routing\RouteCollectorProxy; // penting
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\{UserService, AuthService, AccountActionService};
use App\Http\Middleware\JwtMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Validators\UserValidator;
use App\Models\User;

return function (App $app) {
    // ambil container sekali
    $container = $app->getContainer();

    // Root
    $app->get('/', function (Request $req, Response $res) {
        $res->getBody()->write(json_encode(['status' => 'ok']));
        return $res->withHeader('Content-Type', 'application/json');
    });

    // ===== AUTH =====
    $app->post('/auth/register', function (Request $req, Response $res) use ($container) {
        /** @var AuthService $auth */
        $auth = $container->get(AuthService::class);
        /** @var UserService $svc */
        $svc  = $container->get(UserService::class);

        $body = (array)$req->getParsedBody();
        $errors = UserValidator::forCreate($body);
        if ($errors || empty($body['password']) || strlen($body['password']) < 6) {
            $errors['password'] = $errors['password'] ?? 'Password min 6 karakter.';
            $res->getBody()->write(json_encode(['errors' => $errors]));
            return $res->withStatus(422)->withHeader('Content-Type', 'application/json');
        }
        if ($svc->emailTaken($body['email'])) {
            $res->getBody()->write(json_encode(['message' => 'email sudah terpakai']));
            return $res->withStatus(409)->withHeader('Content-Type', 'application/json');
        }

        $user = $auth->register($body['name'], $body['email'], $body['password']);
        // kirim verifikasi
        $container->get(AccountActionService::class)->sendVerification($user);

        $res->getBody()->write($user->toJson(JSON_UNESCAPED_UNICODE));
        return $res->withStatus(201)->withHeader('Content-Type', 'application/json');
    });

    $app->post('/auth/login', function (Request $req, Response $res) use ($container) {
        /** @var AuthService $auth */
        $auth = $container->get(AuthService::class);
        $meta = [
            'ip' => $req->getServerParams()['REMOTE_ADDR'] ?? null,
            'ua' => $req->getHeaderLine('User-Agent')
        ];
        $body = (array)$req->getParsedBody();
        $pair = $auth->attempt($body['email'] ?? '', $body['password'] ?? '', $meta);
        if (!$pair) {
            $res->getBody()->write(json_encode(['message' => 'Email/password salah']));
            return $res->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        $res->getBody()->write(json_encode($pair));
        return $res->withHeader('Content-Type', 'application/json');
    });

    // Refresh access + rotate refresh
    $app->post('/auth/refresh', function (Request $req, Response $res) use ($container) {
        /** @var AuthService $auth */
        $auth = $container->get(AuthService::class);
        $body = (array)$req->getParsedBody();
        $plain = $body['refresh_token'] ?? '';

        // ambil user dari Authorization (lebih aman), atau fallback email
        $authUser = $req->getAttribute('auth_user');
        if (!$authUser) {
            $email = $body['email'] ?? null;
            if (!$email) {
                $res->getBody()->write(json_encode(['message'=>'Butuh Authorization header atau email untuk refresh']));
                return $res->withStatus(400)->withHeader('Content-Type','application/json');
            }
            $authUser = User::where('email', $email)->first();
            if (!$authUser) {
                $res->getBody()->write(json_encode(['message'=>'User tidak ditemukan']));
                return $res->withStatus(404)->withHeader('Content-Type','application/json');
            }
        }

        $meta = [
            'ip' => $req->getServerParams()['REMOTE_ADDR'] ?? null,
            'ua' => $req->getHeaderLine('User-Agent')
        ];
        $pair = $auth->refresh($authUser, $plain, $meta);
        if (!$pair) {
            $res->getBody()->write(json_encode(['message'=>'Refresh token invalid/expired']));
            return $res->withStatus(401)->withHeader('Content-Type','application/json');
        }
        $res->getBody()->write(json_encode($pair));
        return $res->withHeader('Content-Type','application/json');
    })->add($container->get(JwtMiddleware::class)); // lindungi endpoint refresh

    // Logout satu sesi
    $app->post('/auth/logout', function (Request $req, Response $res) use ($container) {
        /** @var AuthService $auth */
        $auth = $container->get(AuthService::class);
        $user = $req->getAttribute('auth_user');
        $body = (array)$req->getParsedBody();
        $ok = $auth->logout($user, $body['refresh_token'] ?? '');
        $res->getBody()->write(json_encode(['logout' => $ok]));
        return $res->withHeader('Content-Type','application/json');
    })->add($container->get(JwtMiddleware::class));

    // Logout semua sesi
    $app->post('/auth/logout-all', function (Request $req, Response $res) use ($container) {
        /** @var AuthService $auth */
        $auth = $container->get(AuthService::class);
        $user = $req->getAttribute('auth_user');
        $n = $auth->logoutAll($user);
        $res->getBody()->write(json_encode(['revoked_count' => $n]));
        return $res->withHeader('Content-Type','application/json');
    })->add($container->get(JwtMiddleware::class));

    // Email verification
    $app->post('/auth/send-verification', function (Request $req, Response $res) use ($container) {
        $acc = $container->get(AccountActionService::class);
        $user = $req->getAttribute('auth_user');
        $acc->sendVerification($user);
        $res->getBody()->write(json_encode(['sent' => true]));
        return $res->withHeader('Content-Type','application/json');
    })->add($container->get(JwtMiddleware::class));

    $app->post('/auth/verify', function (Request $req, Response $res) use ($container) {
        $acc = $container->get(AccountActionService::class);
        $authUser = $req->getAttribute('auth_user');
        $token = (string)(($req->getParsedBody()['token'] ?? '') ?: ($req->getQueryParams()['token'] ?? ''));
        if (!$token) {
            $res->getBody()->write(json_encode(['message'=>'Token wajib']));
            return $res->withStatus(400)->withHeader('Content-Type','application/json');
        }
        $ok = $acc->verify($token, $authUser);
        if (!$ok) {
            $res->getBody()->write(json_encode(['message'=>'Token invalid/expired']));
            return $res->withStatus(400)->withHeader('Content-Type','application/json');
        }
        $res->getBody()->write(json_encode(['verified'=>true]));
        return $res->withHeader('Content-Type','application/json');
    })->add($container->get(JwtMiddleware::class));

    // Password reset
    $app->post('/auth/forgot-password', function (Request $req, Response $res) use ($container) {
        $acc = $container->get(AccountActionService::class);
        $email = (string)($req->getParsedBody()['email'] ?? '');
        $user = User::where('email', $email)->first();
        // selalu 200 demi keamanan
        if ($user) $acc->sendReset($user);
        $res->getBody()->write(json_encode(['sent'=>true]));
        return $res->withHeader('Content-Type','application/json');
    });

    $app->post('/auth/reset-password', function (Request $req, Response $res) use ($container) {
        $acc = $container->get(AccountActionService::class);
        $body = (array)$req->getParsedBody();
        $email = (string)($body['email'] ?? '');
        $token = (string)($body['token'] ?? '');
        $pass  = (string)($body['password'] ?? '');
        if (strlen($pass) < 6) {
            $res->getBody()->write(json_encode(['errors'=>['password'=>'Min 6 karakter']]));
            return $res->withStatus(422)->withHeader('Content-Type','application/json');
        }
        $user = User::where('email', $email)->first();
        if (!$user) {
            $res->getBody()->write(json_encode(['message'=>'User tidak ditemukan']));
            return $res->withStatus(404)->withHeader('Content-Type','application/json');
        }
        $ok = $acc->resetPassword($token, $user, $pass);
        if (!$ok) {
            $res->getBody()->write(json_encode(['message'=>'Token invalid/expired']));
            return $res->withStatus(400)->withHeader('Content-Type','application/json');
        }
        $res->getBody()->write(json_encode(['reset'=>true]));
        return $res->withHeader('Content-Type','application/json');
    });

    // ===== USERS (Protected + contoh RBAC) =====
    $app->group('/users', function (RouteCollectorProxy $g) use ($container) {
        // Contoh rute statis dulu (hindari nabrak {id})
        $g->get('/deleted', function (Request $req, Response $res) {
            $q = \App\Models\User::onlyTrashed()->orderBy('id','desc')->get();
            $res->getBody()->write($q->toJson(JSON_UNESCAPED_UNICODE));
            return $res->withHeader('Content-Type','application/json');
        });

        // List
        $g->get('', function (Request $req, Response $res) use ($container) {
            /** @var UserService $svc */
            $svc = $container->get(UserService::class);
            $q = $req->getQueryParams();
            $perPage = isset($q['per_page']) ? (int)$q['per_page'] : 10;
            $filters = ['search' => $q['search'] ?? null];
            $paginator = $svc->list($perPage, $filters);
            $payload = [
                'data' => $paginator->items(),
                'meta' => [
                    'current_page'=>$paginator->currentPage(),
                    'per_page'=>$paginator->perPage(),
                    'total'=>$paginator->total(),
                    'last_page'=>$paginator->lastPage(),
                    'from'=>$paginator->firstItem(),
                    'to'=>$paginator->lastItem(),
                ],
                'links'=>[
                    'first'=>$paginator->url(1),
                    'last'=>$paginator->url($paginator->lastPage()),
                    'prev'=>$paginator->previousPageUrl(),
                    'next'=>$paginator->nextPageUrl(),
                ],
            ];
            $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
            return $res->withHeader('Content-Type','application/json');
        });

        // Detail (batasi id angka agar tidak bentrok rute statis)
        $g->get('/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($container) {
            /** @var UserService $svc */
            $svc = $container->get(UserService::class);
            $user = $svc->get((int)$args['id']);
            if (!$user) {
                $res->getBody()->write(json_encode(['message'=>'Not found']));
                return $res->withStatus(404)->withHeader('Content-Type','application/json');
            }
            $res->getBody()->write($user->toJson(JSON_UNESCAPED_UNICODE));
            return $res->withHeader('Content-Type','application/json');
        });

        $g->post('', function (Request $req, Response $res) use ($container) {
            /** @var UserService $svc */
            $svc = $container->get(UserService::class);
            $body = (array)$req->getParsedBody();
            $errors = UserValidator::forCreate($body);
            if ($errors || empty($body['password']) || strlen($body['password']) < 6) {
                $errors['password'] = $errors['password'] ?? 'Password min 6 karakter.';
                $res->getBody()->write(json_encode(['errors'=>$errors]));
                return $res->withStatus(422)->withHeader('Content-Type','application/json');
            }
            if ($svc->emailTaken($body['email'])) {
                $res->getBody()->write(json_encode(['message'=>'email sudah terpakai']));
                return $res->withStatus(409)->withHeader('Content-Type','application/json');
            }
            $body['password'] = password_hash($body['password'], PASSWORD_BCRYPT);
            $user = $svc->create([
                'name'=>$body['name'], 'email'=>$body['email'], 'password'=>$body['password']
            ]);
            $res->getBody()->write($user->toJson(JSON_UNESCAPED_UNICODE));
            return $res->withStatus(201)->withHeader('Content-Type','application/json');
        });

        $g->put('/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($container) {
            /** @var UserService $svc */
            $svc = $container->get(UserService::class);
            $id = (int)$args['id'];
            $user = $svc->get($id);
            if (!$user) {
                $res->getBody()->write(json_encode(['message'=>'Not found']));
                return $res->withStatus(404)->withHeader('Content-Type','application/json');
            }
            $body = (array)$req->getParsedBody();
            $errors = \App\Http\Validators\UserValidator::forUpdate($body);
            if ($errors) {
                $res->getBody()->write(json_encode(['errors'=>$errors]));
                return $res->withStatus(422)->withHeader('Content-Type','application/json');
            }
            if (isset($body['email']) && $svc->emailTaken($body['email'], $id)) {
                $res->getBody()->write(json_encode(['message'=>'email sudah terpakai']));
                return $res->withStatus(409)->withHeader('Content-Type','application/json');
            }
            if (!empty($body['password'])) {
                if (strlen($body['password']) < 6) {
                    $res->getBody()->write(json_encode(['errors'=>['password'=>'Password min 6 karakter.']]));
                    return $res->withStatus(422)->withHeader('Content-Type','application/json');
                }
                $body['password'] = password_hash($body['password'], PASSWORD_BCRYPT);
            }
            $user = $svc->update($id, array_intersect_key($body, array_flip(['name','email','password'])));
            $res->getBody()->write($user->toJson(JSON_UNESCAPED_UNICODE));
            return $res->withHeader('Content-Type','application/json');
        });

        $g->delete('/{id:[0-9]+}', function (Request $req, Response $res, array $args) {
            $user = \App\Models\User::find($args['id']);
            if (!$user) {
                $res->getBody()->write(json_encode(['message'=>'Not found']));
                return $res->withStatus(404)->withHeader('Content-Type','application/json');
            }
            $user->delete();
            $res->getBody()->write(json_encode(['deleted'=>true]));
            return $res->withHeader('Content-Type','application/json');
        });

        $g->post('/{id:[0-9]+}/restore', function (Request $req, Response $res, array $args) {
            $user = \App\Models\User::onlyTrashed()->find($args['id']);
            if (!$user) {
                $res->getBody()->write(json_encode(['message'=>'Not found or not deleted']));
                return $res->withStatus(404)->withHeader('Content-Type','application/json');
            }
            $user->restore();
            $res->getBody()->write($user->toJson(JSON_UNESCAPED_UNICODE));
            return $res->withHeader('Content-Type','application/json');
        });

        $g->delete('/{id:[0-9]+}/force', function (Request $req, Response $res, array $args) {
            $user = \App\Models\User::withTrashed()->find($args['id']);
            if (!$user) {
                $res->getBody()->write(json_encode(['message'=>'Not found']));
                return $res->withStatus(404)->withHeader('Content-Type','application/json');
            }
            $user->forceDelete();
            $res->getBody()->write(json_encode(['force_deleted'=>true]));
            return $res->withHeader('Content-Type','application/json');
        });

    })
    ->add(new RoleMiddleware(['admin','manager']))        // RBAC
    ->add($container->get(JwtMiddleware::class));         // JWT protect
};
