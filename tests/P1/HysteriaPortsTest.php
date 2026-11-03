<?php

namespace Tests\P1;

use App\Protocols\Singbox\HysteriaPorts;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use PHPUnit\Framework\TestCase;

class HysteriaPortsTest extends TestCase
{
    public function ports(): array
    {
        return [
            ['443', ['server_port' => 443]],
            ['20000-30000', ['server_ports' => ['20000:30000']]],
            ['443,8443', ['server_ports' => ['443:443', '8443:8443']]],
            ['443,800-900', ['server_ports' => ['443:443', '800:900']]],
            ['1-3,5-7', ['server_ports' => ['1:3', '5:7']]],
            ['443,20000-30000,8443', ['server_ports' => ['443:443', '20000:30000', '8443:8443']]],
            [' 443, 20000 - 30000, 40000-41000 ', ['server_ports' => ['443:443', '20000:30000', '40000:41000']]],
            ['1-65535', ['server_ports' => ['1:65535']]],
        ];
    }

    /** @dataProvider ports */
    public function testPortListsPreserveEveryEntryAndKeepTheSinglePortFallback($ports, $expected): void
    {
        $this->assertSame($expected, HysteriaPorts::build($ports));
        $this->assertSame(
            ['server_port' => (int)explode('-', explode(',', trim($ports))[0])[0]],
            HysteriaPorts::build($ports, false)
        );
    }

    private function hysteria2($class, array $server)
    {
        $method = new \ReflectionMethod($class, 'buildHysteria2');
        $method->setAccessible(true);
        $parameterCount = $method->getNumberOfParameters();
        return $parameterCount === 3
            ? $method->invoke($class, 'fixture-uuid', $server, (object)['speed_limit' => null])
            : $method->invoke($class, 'fixture-uuid', $server);
    }

    public function testLegacyTemplateOnlyHopsForKnownVersionsAtLeast111(): void
    {
        $server = ['host' => 'hy2.example.test', 'port' => '20000-30000', 'tag' => 'n', 'name' => 'n'];
        foreach ([null => false, '1.10.7' => false, '1.11.0' => true, '1.11.15' => true] as $version => $hopping) {
            $node = $this->hysteria2(new SingboxOld(null, [], ['version' => $version === '' ? null : (string)$version]), $server);
            if ($hopping) {
                $this->assertSame(['20000:30000'], $node['server_ports']);
                $this->assertArrayNotHasKey('server_port', $node);
            } else {
                $this->assertSame(20000, $node['server_port']);
                $this->assertArrayNotHasKey('server_ports', $node);
            }
        }
    }

    public function testModernTemplateAlwaysExportsTheFullHoppingList(): void
    {
        $node = $this->hysteria2(new Singbox(null, []), ['host' => 'hy2.example.test', 'port' => '443,800-900', 'name' => 'n']);
        $this->assertSame(['443:443', '800:900'], $node['server_ports']);
        $this->assertArrayNotHasKey('server_port', $node);
    }
}
