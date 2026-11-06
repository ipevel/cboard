<?php

namespace App\Services;

/**
 * 队列与常驻进程可能需要重新读取落盘的站点配置，
 * 否则管理端保存后，长生命周期 worker 仍使用启动时的旧配置。
 */
class TelegramRuntime
{
    public static function refresh(): void
    {
        $file = config_path('v2board.php');
        if (!is_file($file)) return;
        $settings = require $file;
        if (is_array($settings)) config(['v2board' => $settings]);
    }
}
