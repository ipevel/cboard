<?php

namespace Tests\P1;

use App\Protocols\Clash;
use App\Utils\Helper;

class SubscriptionUriTest extends DatabaseTestCase
{
    public function testClashObfsOnlyAppliesToHttpNetwork(): void
    {
        $server = [
            'name' => 'node', 'host' => 'node.example.test', 'port' => '443',
            'cipher' => 'aes-256-gcm', 'obfs' => 'plain', 'network' => 'tcp',
            'network_settings' => ['Host' => 'cdn.example.test'],
        ];
        $this->assertArrayNotHasKey('plugin', Clash::buildShadowsocks('uuid', $server));

        $server['network'] = 'http';
        $built = Clash::buildShadowsocks('uuid', $server);
        $this->assertSame('obfs', $built['plugin']);
        $this->assertSame('cdn.example.test', $built['plugin-opts']['host']);
    }

    public function testVmessUriExportsHttpTransportPathAndHost(): void
    {
        $uri = Helper::buildVmessUri('11111111-1111-1111-1111-111111111111', [
            'name' => 'node', 'host' => 'node.example.test', 'port' => '443',
            'network' => 'http', 'tls' => 1,
            'network_settings' => ['path' => '/ray', 'host' => 'cdn.example.test'],
        ]);
        $config = json_decode(base64_decode(substr(trim($uri), strlen('vmess://'))), true);
        $this->assertSame('/ray', $config['path']);
        $this->assertSame('cdn.example.test', $config['host']);
    }

    public function testHysteriaSniIsPercentEncoded(): void
    {
        $uri = Helper::buildHysteriaUri('secret', [
            'name' => 'node', 'host' => 'node.example.test', 'port' => '443', 'version' => 2,
            'insecure' => 0, 'server_name' => 'a b.example.test',
            'down_mbps' => 100, 'up_mbps' => 100, 'obfs' => '', 'obfs_password' => '',
        ]);
        $this->assertStringContainsString('sni=a%20b.example.test', $uri);
        $this->assertStringNotContainsString('sni=a b.example.test', $uri);
    }

    public function testSubscribeUrlToleratesMissingConfiguration(): void
    {
        app()->instance('request', \Illuminate\Http\Request::create('https://panel.example.test'));
        config(['v2board.subscribe_url' => null]);
        $url = Helper::getSubscribeUrl('token-value');
        $this->assertStringContainsString('token=token-value', $url);
    }
}
