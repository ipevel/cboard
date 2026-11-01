<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Models\InviteCode;
use App\Services\CaptchaService;
use App\Services\EmailAddress;
use App\Services\EmailIdentity;
use App\Services\EmailVerificationService;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class CommController extends Controller
{
    private function isEmailVerify()
    {
        return response([
            'data' => (int)config('v2board.email_verify', 0) ? 1 : 0
        ]);
    }

    public function sendEmailVerify(CommSendEmailVerify $request)
    {
        $ip = $request->ip();
        if (RateLimiter::tooManyAttempts($ip, 3)) {
            abort(429, __('Too many requests, please try again later.'));
        }
        RateLimiter::hit($ip, 60);

        app(CaptchaService::class)->verify($request);
        $email = EmailAddress::normalize($request->input('email'));
        $mode = $request->input('isforget');
        if (!in_array($mode, [null, 0, 1, '0', '1'], true)) abort(422, '验证码用途无效');
        if ((int)config('v2board.email_whitelist_enable', 0) && !Helper::emailSuffixVerify($email,
            config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))) {
            abort(500, __('Email suffix is not in the Whitelist'));
        }
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = substr($email, 0, strrpos($email, '@'));
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) abort(500, __('Gmail alias is not supported'));
        }
        $codes = app(EmailVerificationService::class);
        if ($mode === 1 || $mode === '1') $codes->sendRecovery($email);
        elseif ($mode === 0 || $mode === '0') $codes->sendRegistration($email);
        else $this->sendLegacyPurposeCode($codes, $email); // 未声明用途：按邮箱是否已注册推断，保持既有前端可用
        return response([
            'data' => true
        ]);
    }

    /**
     * 兼容未传 isforget 的调用方：已注册邮箱按找回密码发码，未注册邮箱按注册发码。
     * 已注册邮箱的收件人始终取库内存储地址，不采用调用方拼写。
     */
    private function sendLegacyPurposeCode(EmailVerificationService $codes, string $email): void
    {
        if (EmailIdentity::find($email)) $codes->sendRecovery($email);
        else $codes->sendRegistration($email);
    }

    public function pv(Request $request)
    {
        $inviteCode = InviteCode::where('code', $request->input('invite_code'))->first();
        if ($inviteCode) {
            $inviteCode->pv = $inviteCode->pv + 1;
            $inviteCode->save();
        }

        return response([
            'data' => true
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
