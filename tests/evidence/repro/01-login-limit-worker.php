<?php

/**
 * Concurrency worker for the login-limit negative proof.
 * Usage: php 01-login-limit-worker.php <cache-dir> <index> <user-id>
 */

require __DIR__ . '/../../P1/bootstrap.php';

use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Requests\Passport\AuthLogin;
use App\Models\User;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Tests\P1\Environment;

Environment::boot(false);
$directory = $argv[1];
$worker = $argv[2];
$userId = (int)$argv[3];
Cache::swap(new Repository(new FileStore(new Filesystem(), $directory)));
config(['v2board.password_limit_enable' => 1, 'v2board.password_limit_count' => 100,
    'v2board.password_limit_expire' => 60]);

$user = User::find($userId);
if (!$user) throw new RuntimeException('fixture user is missing');
file_put_contents($directory . '/' . $worker . '.ready', 'ready');
$deadline = microtime(true) + 15;
while (!is_file($directory . '/go')) {
    if (microtime(true) > $deadline) throw new RuntimeException('barrier timeout');
    usleep(10000);
}

$request = new AuthLogin();
$request->server->set('REMOTE_ADDR', '198.51.100.' . $worker);
$request->merge(['email' => $user->email, 'password' => 'wrong-password']);
try {
    (new AuthController())->login($request);
    echo "unexpected-success\n";
} catch (\Throwable $error) {
    echo "rejected\n";
}
