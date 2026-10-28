<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Utils\Dict;
use App\Services\CaptchaService;
use Illuminate\Support\Facades\Http;

class CommController extends Controller
{
    public function config()
    {
        $captcha = CaptchaService::publicSettings();
        $captchaProvider = $captcha['captcha_provider'];
        return response([
            'data' => array_merge([
                'tos_url' => config('v2board.tos_url'),
                'is_email_verify' => (int)config('v2board.email_verify', 0) ? 1 : 0,
                'is_invite_force' => (int)config('v2board.invite_force', 0) ? 1 : 0,
                'email_whitelist_suffix' => (int)config('v2board.email_whitelist_enable', 0)
                    ? $this->getEmailSuffix()
                    : 0,
                'app_description' => config('v2board.app_description'),
                'app_url' => config('v2board.app_url'),
                'logo' => config('v2board.logo'),
                'app_name' => config('v2board.app_name'),
                'is_register' => (int)config('v2board.stop_register', 0) ? 0 : 1,
                'captcha_type' => $captchaProvider,
                'turnstile_site_key' => $captchaProvider === 'turnstile' ? config('v2board.turnstile_site_key') : null,
            ], $captcha)
        ]);
    }

    private function getEmailSuffix()
    {
        $suffix = config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT);
        if (!is_array($suffix)) {
            return preg_split('/,/', $suffix);
        }
        return $suffix;
    }
}
