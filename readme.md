# Cboard

A modern proxy management panel built on Laravel. Cboard provides a complete solution for running a proxy service: plans, orders, users, nodes, subscriptions, gift cards, coupons, support tickets, a knowledge base, passkey authentication, and subscription auditing.

## Badges

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-blue.svg)
![Redis](https://img.shields.io/badge/Redis-required-red.svg)

## Introduction

Cboard is a proxy management panel that handles the full lifecycle of a proxy service. It manages subscription plans, user accounts, orders and payments, server nodes across multiple protocols, subscription links, gift cards, coupons, support tickets, and a knowledge base. It also includes advanced features such as passkey (WebAuthn) authentication and a subscription audit trail.

## Features

- **Plans & Orders** — Create subscription plans with configurable pricing, traffic quotas, and durations. Users place orders, apply coupons, and complete checkout through supported payment gateways.
- **User Management** — Full user lifecycle: registration, authentication (email, captcha, and passkeys), traffic tracking, renewal, suspension, and commission tracking through invite codes.
- **Node Management** — Manage server nodes across multiple protocols: VMess, VLess, Trojan, Shadowsocks, Hysteria, TUIC, AnyTLS, and V2Node. Organize nodes into groups and routes.
- **Subscription Generation** — Generate subscription links compatible with Clash, Clash Meta, Sing-box, Surge, Stash, Surfboard, Shadowrocket, V2RayN, V2RayNG, Quantumult X, and more.
- **Gift Cards** — Issue and redeem gift cards for account credit.
- **Coupons** — Create discount coupons with configurable limits and validity periods.
- **Support Tickets** — User-to-staff ticket system with message threading and Telegram notifications.
- **Knowledge Base** — Publish articles and documentation for users.
- **Notices** — Broadcast announcements to users.
- **Payment Gateways** — Multiple payment integrations including Alipay, Stripe, Coinbase, BTCPay, CoinPayments, EPay, Epusdt, Paytaro, and WeChat Pay.
- **Subscription Audit** — Record and review subscription pull requests with IP, user agent, and request details for abuse detection.
- **Leak Probe** — A/B testing tool to identify account sharing by comparing node access across controlled groups.
- **Payment Ledger** — Track payment attempts and receipts with idempotent settlement and balance crediting.
- **Passkey Authentication** — WebAuthn-based passwordless login for enhanced security.
- **Telegram Integration** — Bind Telegram accounts, receive traffic and ticket notifications, and manage accounts via bot commands.
- **Multi-language** — English and Chinese localization.

## Quick Start

### Requirements

- Docker Engine with the Compose v2 plugin
- A host port for the panel (defaults to `7001`)

### Installation

Clone the repository and run the one-command installer:

```sh
git clone https://github.com/ipevel/cboard
cd cboard
./init.sh
```

The installer checks for Docker, generates `deploy/.env` with random credentials, starts the full stack (PHP-FPM, nginx, MySQL, Redis, and Horizon), waits for MySQL, and runs the installation wizard. It prints the site URL, the admin console URL, and the generated administrator password once at the end.

Optionally set the administrator email up front and expose the panel on a custom port:

```sh
ADMIN_ACCOUNT=admin@example.com CBOARD_PORT=8000 ./init.sh
```

The application image is pulled from `ghcr.io/ipevel/cboard:latest`. To run a locally built image instead, tag it and set `CBOARD_IMAGE` before starting the stack.

### Updating

Pull the latest image, recreate the containers, and apply database migrations:

```sh
./update.sh
```

### Manual installation (without Docker)

Cboard is a standard Laravel application and runs on PHP 8.0 or higher, Composer, MySQL 5.7 or higher, and Redis.

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
cp .env.example .env
php artisan key:generate
# Edit .env with your database and Redis connection details.
php artisan v2board:install
php artisan horizon
```

Point your web server (Nginx, Apache, or PHP-FPM) at the `public/` directory. Cboard also supports running as a Webman application via `webman.php` or with PM2 via the included `pm2.yaml`. Configure the scheduler to run every minute for background tasks such as traffic updates, order checks, and subscription audit pruning.

## License

Cboard is open-sourced software licensed under the [MIT license](LICENSE).