# deep-ffb0aec 证据包

本报告只回答三件事：证据在哪里、如何复算、哪些没有做到。所有结论都指向本仓库内的命令与输出文件，不写形容词式结论。

## 0. 基线与范围

| 项目 | 值 |
| --- | --- |
| 发布仓 | `/root/rebuild/cboard`，`master` = `ffb0aecc14cc781c921bab8ee6c72cd32a0c22f2` |
| 修复前（pre） | `da53f6dbcb448042e56b56ef73cc254e703450eb` |
| 修复后（post） | `ffb0aec`（同上） |
| 证据分支 | `evidence/deep-ffb0aec`（worktree `/root/rebuild/deep-post`） |
| pre worktree | `/root/rebuild/deep-pre`（detached at `da53f6d`） |
| 运行时 | PHP 8.2.35（`ghcr.io/ipevel/cboard:latest` 的 CLI），PHPUnit 9.6.38 |
| 数据库 | MySQL 8.4.13 @ `127.0.0.1:33079`，库 `v2board_p1_deep`，`sql_mode=STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION`，`collation_server=utf8mb4_unicode_ci` |

`master` 未被修改；本分支未 push、未打标签；`/root/rebuild/shuv2b` 未被触碰。

## 1. 第一步：让测试真能跑

### 1.1 补齐的 harness 文件

发布仓原先只有 8 个测试文件（1169 行），全部 `extends DatabaseTestCase`，而该类定义在未搬入的 `tests/P1/Environment.php`。补齐内容：

| 文件 | 来源 | 是否修改 |
| --- | --- | --- |
| `tests/p1.xml` | `/root/rebuild/upstream-follow/tests/p1.xml` | 未修改（与原件逐字节相同） |
| `tests/P1/Environment.php` | 同上 | 未修改（与原件逐字节相同） |
| `tests/P1/PaymentHttpFixture.php` | 同上 | 未修改（与原件逐字节相同） |
| `tests/P1/bootstrap.php` | 同上 | **有修改**，见 1.2 |

`diff tests/p1.xml /root/rebuild/upstream-follow/tests/p1.xml`、`diff tests/P1/Environment.php …`、`diff tests/P1/PaymentHttpFixture.php …` 均无输出。

未搬入跟进树的其他测试文件（`AdminManagementTest`、`PaymentTest`、`PasskeyTest` 等 40 余个），因为它们覆盖的代码不在本批修复范围内。

### 1.2 对 bootstrap 的唯一改动及理由

原始 bootstrap 直接用 `require $autoload`，不设置 `error_reporting`。在 PHP 8.2 下，应用代码里 `$this->config = $config;`（`app/Payments/StripeALL.php:11` 等 5 个 Stripe 适配器）触发 `E_DEPRECATED: Creation of dynamic property`；PHPUnit 9.6 的 `Util\ErrorHandler` 在 `convertDeprecationsToExceptions` 为真时把它转成 `PHPUnit\Framework\Error\Deprecated`，测试在第一个断言之前就报错。

原始状态下的实测（`tests/evidence/step0-harness-absent.txt` 证明缺类，`tests/evidence/step1-sqlite-strict-diagnostics.txt` 证明诊断问题）：

```
ERRORS!
Tests: 76, Assertions: 402, Errors: 7, Skipped: 1.
```

改动仅为在 bootstrap 顶部加 14 行：默认 `error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED)`，设 `P1_DIAGNOSTICS=strict` 时恢复 `E_ALL`。这样默认跑出可判读的断言结果，同时保留一条能复现原始失败的命令。

- `diff -u` 输出见本节上方表格所述；完整 diff 只有这一段。
- 未改动任何测试文件（8 个测试与 `PasswordLoginLimitWorker.php` 与跟进树逐字节相同，`diff` 无输出）。

### 1.3 真实数字

SQLite（默认，`:memory:`）：

```
$ docker run --rm --network host -v /root/rebuild/deep-post:/app \
    -v /root/rebuild/upstream-follow/vendor:/vendor:ro -w /app \
    -e P1_VENDOR_AUTOLOAD=/vendor/autoload.php -e P1_SOURCE_ROOT=/app \
    --entrypoint php ghcr.io/ipevel/cboard:latest /vendor/bin/phpunit -c tests/p1.xml
Tests: 76, Assertions: 481, Skipped: 1.
```
输出文件：`tests/evidence/step1-sqlite.txt`

MySQL（utf8mb4_unicode_ci）：

```
$ … -e P1_DB_DRIVER=mysql -e P1_DB_HOST=127.0.0.1 -e P1_DB_PORT=33079 \
    -e P1_DB_DATABASE=v2board_p1_deep -e P1_DB_USER=root -e P1_DB_PASSWORD=root \
    /vendor/bin/phpunit -c tests/p1.xml
OK (76 tests, 484 assertions)
```
输出文件：`tests/evidence/step1-mysql.txt`

| 后端 | 用例 | 断言 | 失败 | 错误 | skip |
| --- | --- | --- | --- | --- | --- |
| SQLite | 76 | 481 | 0 | 0 | 1 |
| MySQL | 76 | 484 | 0 | 0 | 0 |

两个后端的用例数都是 76（`--testdox` 列出 76 项）。

唯一 skip 是 `TelegramBindingTest::testTelegramMigrationIsRepeatableAndDoesNotTrustOrEraseOldBindings`，理由为测试内联的 `markTestSkipped('Uses the production MySQL DDL')`（`tests/P1/TelegramBindingTest.php:213`），即该用例只接受 MySQL 生产 DDL，SQLite 上按测试自身设计跳过；MySQL 上它实际执行并通过（skip 数从 1 变 0）。

断言数差异（481 vs 484）来自同一个用例在 MySQL 上多走的 3 条断言，不是失败。

原始诊断失败的复现命令（保留作为对照）：

```
$ … -e P1_DIAGNOSTICS=strict /vendor/bin/phpunit -c tests/p1.xml
Tests: 76, Assertions: 402, Errors: 7, Skipped: 1.
```
输出文件：`tests/evidence/step1-sqlite-strict-diagnostics.txt`

在 PHP 8.0 上（无 dynamic property 弃用）原始 `E_ALL` 也能全绿，说明这 7 个错误是 PHP 8.2 诊断而非逻辑失败：

```
$ docker run … --entrypoint php php:8.0-cli /vendor/bin/phpunit -c tests/p1.xml
Tests: 76, Assertions: 481, Skipped: 1.
```
输出文件：`tests/evidence/step1-sqlite-php80-strict.txt`

### 1.4 顺带查到的交叉验证

把 post 树的测试跑在 pre 树的代码上（`P1_SOURCE_ROOT=/pre`），结果：

```
Tests: 76, Assertions: 333, Errors: 10, Failures: 13, Skipped: 1.
```
输出文件：`tests/evidence/crosscheck-post-tests-vs-pre-code.txt`

即这批测试确实对四项修复有区分力（在修复前的代码上有 23 个用例不通过），不是“写了就绿”的空测试。

## 2. 第二步：四项修复的负向证明

四个脚本都放在 `tests/evidence/repro/`，通过 `P1_SOURCE_ROOT` 切换 pre/post，输入与代码完全相同。所有原始输出（含完整输入报文）在 `tests/evidence/pre-*.php.json` 与 `tests/evidence/post-*.php.json`。

复算命令（以第 2 项为例，其余只需替换脚本名）：

```
$ docker run --rm --network host -v /root/rebuild/deep-post:/app -v /root/rebuild/deep-pre:/pre:ro \
    -v /root/rebuild/upstream-follow/vendor:/vendor:ro -w /app \
    -e P1_VENDOR_AUTOLOAD=/vendor/autoload.php -e P1_SOURCE_ROOT=/pre \
    --entrypoint php ghcr.io/ipevel/cboard:latest /app/tests/evidence/repro/02-payment-callback.php
```
（并发场景需追加 `-e P1_DB_DRIVER=mysql -e P1_DB_HOST=… -e P1_DB_PORT=… -e P1_DB_DATABASE=… -e P1_DB_USER=… -e P1_DB_PASSWORD=…`）

### 2.1 登录限流

输入：
- A：单 IP `203.0.113.7`，80 次 `POST /api/v1/passport/auth/login`，邮箱在 `nobody0..79@example.test` 间轮换，密码固定错误（撞库）。
- B：同一账号 `limit-b@example.test`，10 次错误密码。
- C：8 个独立进程同时用错误密码登录同一账号，错误次数上限临时提到 100（只看计数是否丢更新）。

| 场景 | pre (`da53f6d`) | post (`ffb0aec`) |
| --- | --- | --- |
| A 80 次撞库 | `{"500 Incorrect email or password": 80}`，0 次 429 | `{"500 …": 60, "429 Too many requests…": 20}` |
| B 同账号 10 次 | 第 6 次起 `There are too many password errors…` | 第 6 次起同样文案 |
| C 8 并发计数 | `PASSWORD_ERROR_LIMIT_…` = **1**（8 次失败只记到 1 次） | `PASSWORD_LOGIN_ERRORS_V2:user:…` = **8** |

结论：pre 无 IP 维度限流（80 次全部进入密码校验），且错误计数在并发下丢更新（8 次只记 1 次）；post 在第 61 次起返回 429，并发计数无丢失。B 场景两棵树都锁定，因为该路径的账号级限流在 pre 上已经存在，这属于“宣称的缺陷在本树不可复现”的部分，如实记录。

输出：`tests/evidence/{pre,post}-01-login-limit.php.json`

### 2.2 支付回调金额/币种

输入：签名全部用商户 key 正确签出（`md5` 排序拼接），仅报文内容异常；订单金额 100.00 CNY。7 个攻击用例 + 1 个对照用例，两个适配器各跑一遍：

| 用例 | pre 结果 | post 结果 |
| --- | --- | --- |
| 少付 `money=1.00` | 订单 status→1，落 1 条 receipt | 500，status 0，0 receipt |
| 多付 `money=101.00` | status→1 | 500，status 0 |
| `money=0.00` | status→1 | 500 |
| `money=-0.01` | status→1 | 500 |
| `money=1.001`（分位溢出） | status→1 | 500 |
| `currency=USD` + `money=100.00` | status→1 | 500 |
| 省略 `money` | status→1 | 500 |
| 对照 `money=100.00` | status→1 | status→1 |

两个适配器（`EPayQrcode`、`Paytaro`）结果一致：pre 接受 14/14 攻击用例并完成结算（订单状态置 1 并写 receipt），post 接受 0/14，对照用例两棵树都正常结算。

输出：`tests/evidence/{pre,post}-02-payment-callback.php.json`（每个用例带 `input` 原文，含签名）。

### 2.3 节点私钥

输入：VLESS 节点，`tls_settings` 同时放入 `tls_key`（真实生成的 RSA 私钥）、`tls_cert`、`private_key`、`ech_key`、`dns_env`、`public_key`、`short_id`，然后以普通订阅用户身份调用 `ServerService::getAvailableVless()`。

| 字段 | pre | post |
| --- | --- | --- |
| `tls_key` / `private_key` / `ech_key` | 已脱敏（本树不可复现） | 脱敏 |
| `dns_env` | **明文返回**（`SECRET-DNS-ENV`） | 脱敏 |
| `tls_cert` / `server_name` / `public_key` / `short_id` | 保留 | 保留 |
| 整个响应 JSON 是否含 `SECRET-` 标记 | `true` | `false` |

结论：pre 会带出 `dns_env`，post 不带；`tls_key`/`private_key`/`ech_key` 在 pre 上就已经被过滤，属于“宣称的缺陷在本树不可复现”的部分，如实记录。此项修复的实际增量是 `dns_env`（`app/Services/ServerService.php:42`、`:208` 把 `array_flip(['private_key','ech_key','tls_key','dns_env'])` 改为无条件集合）。

输出：`tests/evidence/{pre,post}-03-node-private-key.php.json`

### 2.4 支付结算冻结

输入分三组：

| 用例 | 输入 | pre | post |
| --- | --- | --- | --- |
| 4a `EPayQrcode` 无金额 | 有效冻结 attempt，`settle()` 报文不带 `amount`/`currency`（legacy signature-only 形状） | 返回 `true`，订单 status→1，写 receipt | 返回 `false`，status 0，无 receipt |
| 4a `Paytaro` 无金额 | 同上 | `true`，status→1 | `false`，status 0 |
| 4a 对照（带冻结金额） | `amount` = attempt 冻结总额，`currency=CNY` | `true` | `true` |
| 4b `StripeALL` 无 attempt | Stripe 网关、无冻结 attempt 行 | `false` | `false` |
| 4b `StripeCredit` 无 attempt | 同上 | `false` | `false` |

结论：4a 是本次 `PaymentSettlementService::amountMatches()` 的真实增量（`app/Services/PaymentSettlementService.php:87`：`if (!array_key_exists('amount', $payment)) return !in_array($method, ['EPayQrcode','Paytaro'], true);`），pre 放行、post 拒绝，且对照用例两棵树都通过。4b 在 pre 上已被拒绝——那条 Stripe 冻结校验属于 `da53f6d`（上一个提交）引入，不在 `da53f6d..ffb0aec` 区间内，如实标注为“本树不可复现”。

输出：`tests/evidence/{pre,post}-04-settlement-freeze.php.json`

## 3. 第三步：变异测试

### 3.1 环境与可执行性

老 Laravel 8 树自带的 `nikic/php-parser` 是 v5.9.0，而 Infection 0.27.x 要求 `^4.15`，两者不兼容（实测崩溃：`Emulative::__construct(): Argument #1 ($phpVersion) must be of type ?PhpVersion, array given`）。改用 Infection 0.31.9（要求 `nikic/php-parser ^5.3`、`php ^8.2`）后正常运行。运行镜像为自建 `php-infection:8.2`（php:8.2-cli + pcov + pdo_mysql + composer）。

Infection 需要根目录存在 `phpunit.xml` 才能定位测试套件，因此新增 `phpunit.xml`（指向 `tests/P1`）。这属于运行器配置，不影响业务代码。

### 3.2 分数

范围：`app/Services/PasswordLoginLimiter.php`、`app/Services/PaymentSettlementService.php`、`app/Http/Controllers/V1/Passport/AuthController.php`（共 372 个变异体）。

| 文件 | 变异体 | 被杀死 | 逃逸 | MSI |
| --- | --- | --- | --- | --- |
| `PasswordLoginLimiter.php` | 90 | 59 | 31 | 65% |
| `PaymentSettlementService.php` | 272 | 141 | 131 | 51% |
| `AuthController.php` | 10 | 10 | 0 | 100% |
| 合计 | 372 | 210 | 162 | **56.45%** |

（MSI 数值取自 Infection 自身 `stats.msi = 56.45`，与上表逐文件四舍五入一致。）

原始输出：
- `tests/evidence/infection-combined-run.txt`（合并范围 372 个变异体，56%）
- `tests/evidence/infection-limiter-run.txt` / `-summary.log` / `.log`
- `tests/evidence/infection-settle-run.txt` / `-summary.log` / `.log`
- `tests/evidence/infection-auth-run.txt` / `-summary.log` / `.log`
- `tests/evidence/infection.log`（372 个变异体的逐条 diff，含逃逸清单）

复算命令：

```
$ docker run --rm --network host -v /root/rebuild/deep-post:/app \
    -v /root/.hermes/cache/scratch/infection/vendor:/infection:ro -w /app \
    -e P1_VENDOR_AUTOLOAD=/app/vendor/autoload.php -e P1_SOURCE_ROOT=/app \
    --entrypoint php php-infection:8.2 /infection/bin/infection \
    --configuration=infection.json5 --threads=8 --no-interaction --no-progress \
    --filter=app/Services/PasswordLoginLimiter.php,app/Services/PaymentSettlementService.php,app/Http/Controllers/V1/Passport/AuthController.php
```

（`--filter` 必须用逗号列表；单文件写法 `--filter=a.php,b.php` 才是多文件，写成两次单文件会得到 90/272/10 的逐文件结果。两种跑法已分别留档。）

### 3.3 逃逸变异体的性质

162 个逃逸中占比最高的几类：`CastInt` 35、`IncrementInteger` 24、`DecrementInteger` 24、`LogicalOr` 13、`FalseValue` 8、`ReturnRemoval` 8。典型例子（`tests/evidence/infection-limiter.log` 第 1 条）：

```
-        foreach (['ip:' . hash('sha256', (string) $request->ip()) => 60, ...]
+        foreach (['ip:' . hash('sha256', $request->ip()) => 60, ...]
```
`$request->ip()` 在 PHP 8 上恒返回字符串，`(string)` 是防御性转换，去掉不改变行为——这类逃逸是等价变异体，不是测试漏洞。

但 `IncrementInteger`（窗口 60→59/61）与 `DecrementInteger`（全局 1200→1199）的逃逸是真实覆盖缺口：现有测试只断言“第 61 次起 429”，不校验窗口长度与全局阈值的精确值。这一点在报告里如实列为“测试杀伤力不足”，未通过加测试来抬分（本任务不改业务逻辑，也不为凑分数改测试）。

## 4. 第四步：可复算材料

### 4.1 目录

```
tests/evidence/
  repro/                          # 第二步的输入语料与脚本（原始 PHP，含完整报文）
    01-login-limit.php
    01-login-limit-worker.php
    02-payment-callback.php
    03-node-private-key.php
    04-settlement-freeze.php
    support.php
  pre-*.php.json / post-*.php.json # 四项的原始输出快照（含 input 原文与签名）
  step0-harness-absent.txt         # 补齐前的失败原文
  step1-sqlite.txt                 # 第一步 SQLite 输出
  step1-mysql.txt                  # 第一步 MySQL 输出
  step1-sqlite-strict-diagnostics.txt
  step1-sqlite-php80-strict.txt
  crosscheck-post-tests-vs-pre-code.txt
  infection*.log / *-run.txt       # 第三步原始输出
  replay-sqlite/                   # deep-replay.sh 的完整一次运行（SQLite）
scripts/
  deep-replay.sh                   # 一条命令重跑第一步与第二步
  deep-replay-verdict.py           # 逐项校验不变式，用退出码表达结论
```

### 4.2 `scripts/deep-replay.sh`

用法：

```
$ DEEP_VENDOR_DIR=/path/to/vendor DEEP_PRE_TREE=/path/to/pre-checkout \
  bash scripts/deep-replay.sh
```

可选环境变量：`P1_DB_DRIVER`（默认 sqlite）、`P1_DB_HOST/PORT/DATABASE/USER/PASSWORD`、`DEEP_PHP_IMAGE`、`DEEP_OUT_DIR`。脚本会在干净 clone 上：
1. 跑 `tests/p1.xml`（第一步）；
2. 对四个脚本分别跑 pre 与 post（第二步）；
3. 调 `deep-replay-verdict.py` 校验 12 条不变式。

退出码：`0` 全部不变式成立；`1` 有不变式被破坏；`2` 环境不可用（缺 vendor、缺 pre 树、缺 docker），此时不会执行任何测试。

实测：

```
$ DEEP_VENDOR_DIR=/root/rebuild/upstream-follow/vendor DEEP_PRE_TREE=/root/rebuild/deep-pre \
  DEEP_OUT_DIR=…/tests/evidence/replay-sqlite bash scripts/deep-replay.sh
VERDICT PASS      # exit 0

$ 同上 + P1_DB_DRIVER=mysql …
VERDICT PASS      # exit 0，step1 = OK (76 tests, 484 assertions)
```

两次运行输出分别在 `tests/evidence/replay-sqlite/`（SQLite）与 `tests/evidence/replay-mysql/`（MySQL），各含 `step1-phpunit.txt` 与 8 个 pre/post JSON。

干净 clone 验证（证明不依赖本 worktree 的残留文件，只靠提交内容 + 外部 vendor）：

```
$ git clone --branch evidence/deep-ffb0aec /root/rebuild/cboard /tmp/clean-clone
$ cd /tmp/clean-clone && DEEP_VENDOR_DIR=… DEEP_PRE_TREE=… bash scripts/deep-replay.sh
VERDICT PASS      # exit 0
```

## 5. 未做到 / 风险

1. **登录限流 B 场景（同账号锁定）在 pre 上不复现**：账号级错误计数在 `da53f6d` 已存在。本批修复的实际增量是 IP 维度限流（429）与并发计数原子化。这一点在 2.1 已写明。
2. **节点私钥的 `tls_key`/`private_key`/`ech_key` 在 pre 上不复现**：pre 的过滤逻辑是 `array_filter(...)` 只挑存在的键，效果与无条件集合相同；实际增量是 `dns_env`。2.3 已写明。
3. **结算冻结 4b（Stripe 无 attempt）在 pre 上不复现**：该守卫属于 `da53f6d`，不在本区间。2.4 已写明。
4. **变异测试 MSI 56.45% 偏低**：162 个逃逸里既有等价变异体（`CastString` 等），也有真实缺口（窗口长度 60、全局阈值 1200 的精确值未被断言）。未通过新增测试抬分，理由是任务约束“不改业务逻辑、不为凑分改测试”；这些缺口以逃逸清单原文形式留档。
5. **第一步 7 个 `E_DEPRECATED` 错误仍在**：只通过 bootstrap 的 `error_reporting` 屏蔽，未改应用代码（`StripeALL::$config` 等动态属性）。若后续升级 PHP 或改用 `#[AllowDynamicProperties]`，可去掉这层屏蔽。`P1_DIAGNOSTICS=strict` 保留原始行为以便复核。
6. **并发场景（2.1 的 C）依赖 MySQL**：SQLite 内存库无法跨进程共享，`deep-replay.sh` 在 SQLite 下会自动把该项标为 skipped，不伪造结果。
7. **`infection.json5` 与 `phpunit.xml` 是新增的运行器配置**，不属于业务代码；若仓库后续引入自己的 PHPUnit 配置，需要合并而不是覆盖。

## 6. 结束标记

```
HARNESS=tests/p1.xml,tests/P1/Environment.php,tests/P1/PaymentHttpFixture.php,tests/P1/bootstrap.php(bootstrap 仅加 14 行诊断开关，其余 3 个文件与跟进树原件逐字节相同)
DB=SQLite 76 用例/0 失败/1 skip；MySQL 76 用例/0 失败/0 skip
NEGATIVE=1 登录限流 pre 0×429 且并发计数 1/8 → post 20×429 且 8/8；2 支付回调 pre 14/14 攻击结算 → post 0/14；3 节点私钥 pre 泄露 dns_env → post 不泄露（tls_key/private_key/ech_key 两棵树均不泄露）；4 结算冻结 pre 无金额也结算 → post 拒绝（Stripe 无 attempt 两棵树均拒绝）
MSI=56.45%（372 变异体，杀 210，逃 162；AuthController 100%，PasswordLoginLimiter 65%，PaymentSettlementService 51%）
BRANCH=evidence/deep-ffb0aec
```
