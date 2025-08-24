<?php
use DI\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Container\ContainerInterface;

use App\Contracts\UserRepositoryInterface;
use App\Repositories\EloquentUserRepository;

use App\Services\{UserService, AuthService, TokenService, MailService, AccountActionService};
use App\Http\Middleware\{CorsMiddleware, JwtMiddleware};

use Psr\Http\Message\ServerRequestInterface as ServerRequest;
use Slim\Exception\HttpException;
use Slim\Exception\HttpSpecializedException;
use App\Http\Responses\Responder;
use App\Exceptions\ValidationException;
use Slim\Exception\HttpUnauthorizedException;



require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$container = new Container();

// Register Eloquent (Capsule)
$container->set('db', function (ContainerInterface $c) {
    $capsule = new Capsule();
    $capsule->addConnection([
        'driver'    => $_ENV['DB_DRIVER'] ?? 'mysql',
        'host'      => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'database'  => $_ENV['DB_DATABASE'] ?? 'slim_demo',
        'username'  => $_ENV['DB_USERNAME'] ?? 'root',
        'password'  => $_ENV['DB_PASSWORD'] ?? '',
        'charset'   => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
        'collation' => $_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci',
        'prefix'    => '',
        'port'      => (int) ($_ENV['DB_PORT'] ?? 3306),
    ]);

    // global Eloquent
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    return $capsule;
});

// setelah Eloquent dan container lain
$container->set(TokenService::class, fn() => new TokenService((int)($_ENV['JWT_REFRESH_TTL_DAYS'] ?? 30)));
$container->set(MailService::class, fn() => new MailService($_ENV['MAIL_FROM_NAME'] ?? 'No Reply', $_ENV['MAIL_FROM_EMAIL'] ?? 'noreply@example.com'));
$container->set(AccountActionService::class, function(\Psr\Container\ContainerInterface $c) {
    return new AccountActionService($c->get(TokenService::class), $c->get(MailService::class));
});
$container->set(AuthService::class, function(\Psr\Container\ContainerInterface $c){
    $secret = $_ENV['JWT_SECRET'] ?? 'changeme';
    $ttl = (int)($_ENV['JWT_TTL_MIN'] ?? 15);
    return new AuthService($secret, $ttl, $c->get(TokenService::class));
});

$container->set(JwtMiddleware::class, function (ContainerInterface $c) {
    return new JwtMiddleware($c->get(AuthService::class));
});


// setelah $container dibuat & Eloquent diset…
$container->set(UserRepositoryInterface::class, function () {
    return new EloquentUserRepository();
});
$container->set(UserService::class, function (\Psr\Container\ContainerInterface $c) {
    /** @var \Illuminate\Database\Capsule\Manager $capsule */
    $capsule = $c->get('db'); // pastikan Eloquent booted
    return new UserService($c->get(UserRepositoryInterface::class));
});

$app = \Slim\Factory\AppFactory::createFromContainer($container);

// CORS middleware
$app->add(new CorsMiddleware());

// Preflight handler (opsional tapi disarankan)
$app->options('/{routes:.+}', function ($req, $res) {
    return $res->withStatus(204);
});

// Create App
// $app = \Slim\Factory\AppFactory::createFromContainer($container);

// Body parsing & error middleware
$app->addBodyParsingMiddleware();

// Debug & Responder (sekali saja)
$debug = filter_var($_ENV['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOLEAN);
$container->set(Responder::class, fn() => new Responder($debug));

// Error middleware (JSON)
$errorMiddleware = $app->addErrorMiddleware($debug, true, true);

// Handler khusus untuk error JWT (Unauthorized)
$errorMiddleware->setErrorHandler(HttpUnauthorizedException::class, function (
    Psr\Http\Message\ServerRequestInterface $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app) {
    $responder = $app->getContainer()->get(\App\Http\Responses\Responder::class);
    $response  = $app->getResponseFactory()->createResponse();
    return $responder->error(
        $response,
        'unauthorized',
        $exception->getMessage(),
        401
    );
});

$errorMiddleware->setDefaultErrorHandler(function (ServerRequest $request, \Throwable $e) use ($app, $debug) {
    $responder = $app->getContainer()->get(\App\Http\Responses\Responder::class);
    $response  = $app->getResponseFactory()->createResponse();

    $status  = 500;
    $code    = 'INTERNAL_ERROR';
    $message = 'Internal server error';
    $details = [];

    if ($e instanceof \App\Exceptions\ValidationException) {
        $status  = 422;
        $code    = 'VALIDATION_ERROR';
        $message = $e->getMessage() ?: 'Data tidak valid';
        $details = ['errors' => $e->errors];
    } elseif ($e instanceof \Slim\Exception\HttpException) {
        // Slim 4: status disimpan di getCode()
        $status  = (int) ($e->getCode() ?: 500);
        if ($status < 100 || $status > 599) { $status = 500; } // clamp
        $code    = 'HTTP_' . $status;
        $message = $e->getMessage() ?: $message;
    } else {
        if ($debug) { $details['exception'] = get_class($e); }
    }

    return $responder->error($response, $code, $message, $status, $details, $debug ? $e : null);
});
// Load routes
(require __DIR__ . '/../src/Routes/api.php')($app);

return $app;
