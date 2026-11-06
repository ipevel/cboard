<?php

namespace Tests\P1;

use App\Services\TelegramService;

class TelegramCommandDiscoveryTest extends DatabaseTestCase
{
    public function testOnlyWellFormedCommandsArePublished(): void
    {
        $dir = sys_get_temp_dir() . '/tg-commands-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/Good.php', <<<'PHP'
<?php
namespace App\Plugins\Telegram\Commands;
class Good extends \App\Plugins\Telegram\Telegram {
    public $command = '/account';
    public $description = '查看账号';
    public function handle($message, $match = []) {}
}
PHP);
        file_put_contents($dir . '/SlashOnly.php', <<<'PHP'
<?php
namespace App\Plugins\Telegram\Commands;
class SlashOnly extends \App\Plugins\Telegram\Telegram {
    public $command = '/bad name!';
    public $description = '非法命令名';
    public function handle($message, $match = []) {}
}
PHP);
        file_put_contents($dir . '/NoDescription.php', <<<'PHP'
<?php
namespace App\Plugins\Telegram\Commands;
class NoDescription extends \App\Plugins\Telegram\Telegram {
    public $command = '/nodesc';
    public $description = '';
    public function handle($message, $match = []) {}
}
PHP);
        try {
            $commands = (new TelegramService())->discoverCommands($dir);
            $this->assertSame([['command' => 'account', 'description' => '查看账号']], $commands);
        } finally {
            array_map('unlink', glob($dir . '/*.php'));
            rmdir($dir);
        }
    }
}
