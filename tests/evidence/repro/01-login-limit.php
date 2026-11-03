<?php

/**
 * Negative proof 1/4: password login limit.
 *
 * Runs identically on the pre tree (da53f6d) and the post tree (ffb0aec) by
 * switching P1_SOURCE_ROOT; the repro only touches AuthController::login, which
 * exists in both trees.
 *
 * Scenario A: 80 wrong-password attempts from one IP against rotating unknown
 *             addresses (credential spraying).
 * Scenario B: 10 wrong-password attempts against one existing account.
 * Scenario C: 8 concurrent wrong-password attempts against one account, with
 *             the error limit raised to 100 so the counter is the only signal.
 */

require __DIR__ . '/../../P1/bootstrap.php';

use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Requests\Passport\AuthLogin;
use App\Models\User;
use App\Services\EmailAddress;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Tests\P1\Environment;

function attemptLogin(string $email, string $password, string $ip)
{
    $request = new AuthLogin();
    $request->server->set('REMOTE_ADDR', $ip);
    $request->merge(['email' => $email, 'password' => $password]);
    try {
        (new AuthController())->login($request);
        return ['status' => 200, 'body' => 'auth_data'];
    } catch (\Throwable $error) {
        return ['status' => method_exists($error, 'getStatusCode') ? $error->getStatusCode() : 0,
            'body' => substr($error->getMessage(), 0, 120)];
    }
}

Environment::boot();
config(['v2board.password_limit_enable' => 1, 'v2board.password_limit_count' => 5,
    'v2board.password_limit_expire' => 60]);

$output = ['tree' => getenv('P1_SOURCE_ROOT') ?: 'default', 'php' => PHP_VERSION,
    'attack_inputs' => [
        'A' => 'POST /api/v1/passport/auth/login from 203.0.113.7, 80 attempts, rotating nobodyN@example.test, password "wrong-password"',
        'B' => 'same endpoint and password, 10 attempts, one existing account limit-b@example.test',
        'C' => '8 concurrent workers, one account, password "wrong-password", error limit raised to 100',
    ]];

// Scenario A: unknown-account spraying from a single IP.
$statuses = [];
for ($i = 0; $i < 80; $i++) {
    $result = attemptLogin('nobody' . $i . '@example.test', 'wrong-password', '203.0.113.7');
    $statuses[$result['status'] . ' ' . $result['body']] = ($statuses[$result['status'] . ' ' . $result['body']] ?? 0) + 1;
}
$output['A_spray_80_from_one_ip'] = $statuses;

// Scenario B: repeated failures against one account.
$user = User::create(['email' => 'limit-b@example.test', 'password' => password_hash('Correct-password', PASSWORD_DEFAULT)]);
$sequence = [];
for ($i = 1; $i <= 10; $i++) {
    $sequence[$i] = attemptLogin($user->email, 'wrong-password', '203.0.113.8');
}
$output['B_same_account_10_attempts'] = $sequence;

// Scenario C: concurrency, error limit raised so only lost updates show up.
// Requires P1_DB_DRIVER=mysql: the workers are separate processes and must share
// one database, which an in-memory SQLite connection cannot provide.
config(['v2board.password_limit_count' => 100]);
if (getenv('P1_DB_DRIVER') !== 'mysql') {
    $output['C_parallel_8'] = 'skipped: worker processes require P1_DB_DRIVER=mysql';
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    return;
}
$directory = sys_get_temp_dir() . '/deep-repro-login-' . bin2hex(random_bytes(6));
mkdir($directory);
$worker = __DIR__ . '/01-login-limit-worker.php';
Cache::swap(new Repository(new FileStore(new Filesystem(), $directory)));
$workers = [];
$count = 8;
for ($i = 0; $i < $count; $i++) {
    $workers[] = new Process([PHP_BINARY, $worker, $directory, (string)$i, (string)$user->id]);
    $workers[$i]->setTimeout(20);
    $workers[$i]->start();
}
foreach ($workers as $index => $process) {
    $deadline = microtime(true) + 15;
    while (!is_file($directory . '/' . $index . '.ready')) {
        if (!$process->isRunning()) break;
        if (microtime(true) > $deadline) break;
        usleep(10000);
    }
}
file_put_contents($directory . '/go', 'go');
foreach ($workers as $process) {
    $process->wait();
    if (!$process->isSuccessful()) {
        $output['C_worker_error'] = substr($process->getOutput() . $process->getErrorOutput(), 0, 400);
    }
}
$output['C_parallel_8'] = [
    'post_counter_key' => (int)Cache::get('PASSWORD_LOGIN_ERRORS_V2:user:' . $user->id, -1),
    'pre_counter_key' => (int)Cache::get('PASSWORD_ERROR_LIMIT_' . EmailAddress::key($user->email), -1),
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
