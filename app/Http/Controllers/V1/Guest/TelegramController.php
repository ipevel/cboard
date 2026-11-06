<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    protected $msg;
    protected $commands = [];
    protected $telegramService;

    public function __construct(Request $request)
    {
        $this->assertWebhookAuthorization($request);
        $this->telegramService = new TelegramService();
    }

    private function assertWebhookAuthorization(Request $request): void
    {
        $token = config('v2board.telegram_bot_token');
        $accessToken = $request->input('access_token');
        // Never turn an unconfigured bot into a publicly predictable credential.
        // Keep the registered webhook URL format compatible with existing bots.
        if (!is_string($token) || trim($token) === '' || !is_string($accessToken) ||
            !hash_equals(md5($token), $accessToken)) {
            abort(401);
        }
    }

    public function webhook(Request $request)
    {
        // A long-lived worker may reuse the route/controller. Authenticate and
        // reset request state on every update, not only during construction.
        $this->msg = null;
        $this->assertWebhookAuthorization($request);
        $this->telegramService = new TelegramService();
        $this->formatMessage($request->input());
        $this->formatChatJoinRequest($request->input());
        $this->handle();
    }

    public function handle()
    {
        if (!$this->msg) return;
        $msg = $this->msg;
        $commandName = explode('@', $msg->command);

        // To reduce request, only commands contains @ will get the bot name
        if (count($commandName) == 2) {
            $botName = $this->getBotName();
            if (is_string($botName) && strcasecmp($commandName[1], $botName) === 0) {
                $msg->command = $commandName[0];
            }
        }

        try {
            foreach (glob(base_path('app//Plugins//Telegram//Commands') . '/*.php') as $file) {
                $command = basename($file, '.php');
                $class = '\\App\\Plugins\\Telegram\\Commands\\' . $command;
                if (!class_exists($class)) continue;
                $instance = new $class();
                if ($msg->message_type === 'message') {
                    if (!isset($instance->command)) continue;
                    if ($msg->command !== $instance->command) continue;
                    $instance->handle($msg);
                    return;
                }
                if ($msg->message_type === 'reply_message') {
                    if (!isset($instance->regex)) continue;
                    if (!preg_match($instance->regex, $msg->reply_text, $match)) continue;
                    $instance->handle($msg, $match);
                    return;
                }
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->telegramService->sendMessage($msg->chat_id, $e->getMessage());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Telegram command failed', ['exception' => get_class($e)]);
            $this->telegramService->sendMessage($msg->chat_id, '操作暂时失败，请稍后重试');
        }
    }

    public function getBotName()
    {
        $response = $this->telegramService->getMe();
        return $response->result->username;
    }

    private function formatMessage(array $data)
    {
        if (!isset($data['message'])) return;
        if (!isset($data['message']['text'])) return;
        // 上游更新按字段类型校验，避免畸形 payload 触发类型错误或越权身份。
        if (!is_string($data['message']['text']) || !is_int($data['message']['chat']['id'] ?? null) ||
            !is_int($data['message']['message_id'] ?? null) || !is_string($data['message']['chat']['type'] ?? null)) return;
        if (($data['message']['chat']['type'] ?? '') === 'private' && isset($data['message']['from']['id']) &&
            (string)$data['message']['from']['id'] !== (string)$data['message']['chat']['id']) return;
        $obj = new \StdClass();
        $text = preg_split('/\s+/u', trim($data['message']['text'])) ?: [''];
        $obj->command = $text[0];
        $obj->args = array_slice($text, 1);
        $obj->chat_id = $data['message']['chat']['id'];
        $obj->message_id = $data['message']['message_id'];
        $obj->message_type = 'message';
        $obj->text = $data['message']['text'];
        $obj->is_private = $data['message']['chat']['type'] === 'private';
        if (isset($data['message']['reply_to_message']['text'])) {
            $obj->message_type = 'reply_message';
            $obj->reply_text = $data['message']['reply_to_message']['text'];
        }
        $this->msg = $obj;
    }

    private function formatChatJoinRequest(array $data)
    {
        if (!isset($data['chat_join_request'])) return;
        if (!isset($data['chat_join_request']['from']['id'])) return;
        if (!isset($data['chat_join_request']['chat']['id'])) return;
        $user = \App\Models\User::where('telegram_id', $data['chat_join_request']['from']['id'])
            ->first();
        if (!$user) {
            $this->telegramService->declineChatJoinRequest(
                $data['chat_join_request']['chat']['id'],
                $data['chat_join_request']['from']['id']
            );
            return;
        }
        $userService = new \App\Services\UserService();
        if (!$userService->isAvailable($user)) {
            $this->telegramService->declineChatJoinRequest(
                $data['chat_join_request']['chat']['id'],
                $data['chat_join_request']['from']['id']
            );
            return;
        }
        $userService = new \App\Services\UserService();
        $this->telegramService->approveChatJoinRequest(
            $data['chat_join_request']['chat']['id'],
            $data['chat_join_request']['from']['id']
        );
    }
}
