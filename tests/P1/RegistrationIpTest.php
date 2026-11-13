<?php
namespace Tests\P1;

use App\Http\Controllers\V1\Admin\UserController;
use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Middleware\TrustProxies;
use App\Http\Requests\Admin\UserFetch;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegistrationIpTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::getSchemaBuilder()->table('v2_user', function (Blueprint $table) { $table->integer('last_login_at')->nullable(); });
        config([
            'trustedproxy.proxies' => ['127.0.0.1', '::1'],
            'v2board.subscribe_url' => 'http://localhost',
            'v2board.show_subscribe_method' => 0,
            'v2board.email_verify' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        parent::tearDown();
    }

    private function registration(string $ip, array $headers = []): AuthRegister
    {
        $request = AuthRegister::create('/register', 'POST', [
            'email' => 'registration-' . bin2hex(random_bytes(5)) . '@example.test',
            'password' => 'fixture-password',
            'register_ip' => '203.0.113.99',
        ], [], [], ['REMOTE_ADDR' => $ip] + $headers);
        (new TrustProxies(app('config')))->handle($request, static function () {});
        return $request;
    }

    public static function addresses(): array
    {
        return [
            ['192.0.2.5', [], '192.0.2.5'],
            ['2001:0DB8:0000:0000:0000:0000:0000:0005', [], '2001:db8::5'],
            ['127.0.0.1', ['HTTP_X_FORWARDED_FOR' => '198.51.100.5, 127.0.0.1'], '198.51.100.5'],
            ['::1', ['HTTP_X_FORWARDED_FOR' => '2001:db8::5, ::1'], '2001:db8::5'],
            ['192.0.2.5', ['HTTP_X_FORWARDED_FOR' => '203.0.113.99', 'HTTP_X_REAL_IP' => '203.0.113.99'], '192.0.2.5'],
            ['invalid-ip', [], null],
        ];
    }

    /** @dataProvider addresses */
    public function testRegistrationRecordsTheResolvedAddressAndIgnoresBodyValues(string $ip, array $headers, ?string $expected): void
    {
        $request = $this->registration($ip, $headers);
        (new AuthController())->register($request);
        $this->assertSame($expected, User::where('email', $request->input('email'))->value('register_ip'));
    }

    public function testLaterLoginDoesNotReplaceTheRegistrationAddress(): void
    {
        $request = $this->registration('192.0.2.5');
        (new AuthController())->register($request);
        $login = AuthLogin::create('/login', 'POST', $request->only('email', 'password'), [], [], ['REMOTE_ADDR' => '198.51.100.6']);
        $this->assertSame(200, (new AuthController())->login($login)->getStatusCode());
        $this->assertSame('192.0.2.5', User::where('email', $request->input('email'))->value('register_ip'));
    }

    public function testExistingAccountsStayUnknown(): void
    {
        $user = $this->user(['balance' => 500])->fresh();
        $this->assertNull($user->register_ip);
        $user->balance = 1000;
        $user->save();
        $this->assertNull($user->fresh()->register_ip);
    }

    public function testAdminFilterValidatesAndNormalizesTheRegistrationAddress(): void
    {
        $rules = (new UserFetch())->rules();
        $valid = ['filter' => [['key' => 'register_ip', 'condition' => '=', 'value' => '2001:0db8:0:0:0:0:0:5']]];
        app('validator')->make($valid, $rules)->validate();

        foreach ([
            [['key' => 'register_ip', 'condition' => '=', 'value' => 'not-an-ip']],
            [['key' => 'register_ip', 'condition' => '>', 'value' => '192.0.2.1']],
            [['key' => 'register_ip', 'condition' => '模糊', 'value' => '192.0.2.1']],
        ] as $filter) {
            $failed = app('validator')->make(['filter' => $filter], $rules)->fails();
            $this->assertTrue($failed, json_encode($filter));
        }

        foreach (['=', '!='] as $condition) {
            $this->assertFalse(app('validator')->make(
                ['filter' => [['key' => 'register_ip', 'condition' => $condition, 'value' => '192.0.2.1']]],
                $rules
            )->fails());
        }
    }

    public function testAdminFilterMatchesNormalizedAddress(): void
    {
        $this->user(['register_ip' => '2001:db8::5']);
        $this->user(['register_ip' => '192.0.2.9']);
        $controller = new UserController();
        $method = new \ReflectionMethod($controller, 'filter');
        $method->setAccessible(true);
        Request::macro('validate', function (array $rules, array $messages = []) {
            return app('validator')->make($this->all(), $rules, $messages)->validate();
        });

        foreach ([['=', '2001:0db8:0:0:0:0:0:5', 1], ['!=', '2001:0db8:0:0:0:0:0:5', 1], ['=', '192.0.2.9', 1]] as [$condition, $value, $expected]) {
            $query = User::query();
            $request = Request::create('/user/fetch', 'GET', [
                'filter' => [['key' => 'register_ip', 'condition' => $condition, 'value' => $value]],
            ]);
            $method->invoke($controller, $request, $query);
            $this->assertSame($expected, $query->count(), $condition . ' ' . $value);
        }
    }
}
