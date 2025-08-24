<?php
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

use Illuminate\Database\Capsule\Manager as Capsule;

$c = new Capsule();
$c->addConnection([
    'driver' => $_ENV['DB_DRIVER'] ?? 'mysql',
    'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'database' => $_ENV['DB_DATABASE'] ?? 'slim_demo',
    'username' => $_ENV['DB_USERNAME'] ?? 'root',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
    'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
    'collation' => $_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci',
    'prefix' => '',
    'port' => (int)($_ENV['DB_PORT'] ?? 3306),
]);
$c->setAsGlobal();
$c->bootEloquent();

$schema = Capsule::schema();

/** users table (email_verified_at + password + softDeletes) */
if (!$schema->hasTable('users')) {
    $schema->create('users', function ($t) {
        $t->bigIncrements('id');
        $t->string('name');
        $t->string('email')->unique();
        $t->string('password');
        $t->timestamp('email_verified_at')->nullable();
        $t->timestamps();
        $t->string('password');
        $t->softDeletes();
    });
    echo "Tabel users dibuat.\n";
} else {
    if (!$schema->hasColumn('users', 'password')) {
        $schema->table('users', fn($t) => $t->string('password'));
        echo "Kolom password ditambah.\n";
    }
    if (!$schema->hasColumn('users', 'deleted_at')) {
        $schema->table('users', fn($t) => $t->softDeletes());
        echo "Kolom deleted_at ditambah.\n";
    }
    if (!$schema->hasColumn('users', 'email_verified_at')) {
        $schema->table('users', fn($t) => $t->timestamp('email_verified_at')->nullable());
        echo "Kolom email_verified_at ditambah.\n";
    }
}

/** roles + pivot */
if (!$schema->hasTable('roles')) {
    $schema->create('roles', function ($t) {
        $t->bigIncrements('id');
        $t->string('name')->unique(); // contoh: admin, manager, user
        $t->string('label')->nullable();
        $t->timestamps();
    });
    echo "Tabel roles dibuat.\n";
}
if (!$schema->hasTable('role_user')) {
    $schema->create('role_user', function ($t) {
        $t->unsignedBigInteger('user_id');
        $t->unsignedBigInteger('role_id');
        $t->timestamps();
        $t->primary(['user_id', 'role_id']);
    });
    echo "Tabel role_user dibuat.\n";
}

/** auth_tokens: refresh/email_verify/password_reset */
if (!$schema->hasTable('auth_tokens')) {
    $schema->create('auth_tokens', function ($t) {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('user_id');
        $t->enum('type', ['refresh', 'email_verify', 'password_reset']);
        $t->string('token_hash', 128); // simpan hash, bukan token asli
        $t->timestamp('expires_at');
        $t->timestamp('revoked_at')->nullable();
        $t->json('meta')->nullable();  // user agent, ip, dll
        $t->timestamps();
        $t->index(['user_id', 'type']);
    });
    echo "Tabel auth_tokens dibuat.\n";
}
// Seed role dasar
$admin = \App\Models\Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
$manager = \App\Models\Role::firstOrCreate(['name' => 'manager'], ['label' => 'Manager']);
$userRole = \App\Models\Role::firstOrCreate(['name' => 'user'], ['label' => 'User']);

// jika ada user id=1, jadikan admin
$first = \App\Models\User::find(1);
if ($first) $first->roles()->syncWithoutDetaching([$admin->id]);


echo "Migrasi selesai.\n";
