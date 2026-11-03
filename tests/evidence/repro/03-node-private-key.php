<?php

/**
 * Negative proof 3/4: node private key handling on the user-facing node list.
 *
 * A VLESS node is stored with TLS material (tls_key, private_key, ech_key,
 * dns_env) plus public fields. The script asks ServerService for the node list a
 * regular subscriber receives and reports which TLS fields survived.
 */

require __DIR__ . '/support.php';

use App\Models\ServerVless;
use App\Models\User;
use App\Services\ServerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

function create_server_table(string $type): void
{
    $schema = DB::getSchemaBuilder();
    $schema->dropIfExists('v2_server_' . $type);
    $schema->create('v2_server_' . $type, function (Blueprint $table) {
        $table->increments('id');
        foreach (['name', 'host', 'port', 'network', 'cipher', 'protocol'] as $field) $table->string($field)->nullable();
        foreach (['group_id', 'route_id', 'tags', 'networkSettings', 'tlsSettings', 'network_settings', 'tls_settings',
                  'encryption_settings', 'ruleSettings', 'dnsSettings', 'obfs_password'] as $field) $table->text($field)->nullable();
        foreach (['server_port', 'tls', 'show', 'sort', 'parent_id', 'created_at', 'updated_at', 'disable_sni',
                  'zero_rtt_handshake', 'up_mbps', 'down_mbps'] as $field) $table->integer($field)->nullable();
        $table->decimal('rate', 10, 3)->default(1);
    });
}

function node_with_secrets(): ServerVless
{
    create_server_table('vless');
    $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($pair, $privateKey);
    $csr = openssl_csr_new(['commonName' => 'node.example.test'], $pair, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $pair, 30, ['digest_alg' => 'sha256']);
    openssl_x509_export($cert, $certPem);

    return ServerVless::create([
        'name' => 'TLS fixture', 'host' => 'node.example.test', 'port' => '443', 'server_port' => 443,
        'tls' => 1, 'network' => 'tcp', 'group_id' => [1], 'show' => 1, 'sort' => 1, 'rate' => 1,
        'tls_settings' => [
            'cert_mode' => 'self', 'server_name' => 'node.example.test',
            'tls_key' => $privateKey, 'tls_cert' => $certPem,
            'private_key' => 'SECRET-REALITY-PRIVATE', 'ech_key' => 'SECRET-ECH-KEY',
            'dns_env' => 'SECRET-DNS-ENV', 'public_key' => 'public-reality-key',
            'short_id' => 'public-short-id',
        ],
        'encryption_settings' => ['private_key' => 'SECRET-ENCRYPTION-PRIVATE', 'method' => 'aes-128-gcm'],
    ]);
}

$secretFields = ['tls_key', 'private_key', 'ech_key', 'dns_env'];
$user = fixture_user(['group_id' => 1, 'transfer_enable' => 1024, 'expired_at' => time() + 3600]);
$node = node_with_secrets();
$servers = (new ServerService())->getAvailableVless($user->fresh());

$tls = $servers[0]['tls_settings'] ?? [];
$encryption = $servers[0]['encryption_settings'] ?? [];

$report = [
    'tree' => getenv('P1_SOURCE_ROOT') ?: 'default',
    'php' => PHP_VERSION,
    'attack_input' => 'ServerService::getAvailableVless() for a subscriber on a node whose tls_settings hold tls_key/private_key/ech_key/dns_env plus public cert fields',
    'node_fields_rendered' => array_keys($tls),
    'tls_secret_fields_present' => array_values(array_filter($secretFields, function ($field) use ($tls) {
        return array_key_exists($field, $tls);
    })),
    'tls_secret_values_leaked' => array_values(array_filter($secretFields, function ($field) use ($tls) {
        return array_key_exists($field, $tls) && $tls[$field] !== null && $tls[$field] !== '';
    })),
    'encryption_private_key_present' => array_key_exists('private_key', $encryption),
    'public_fields_kept' => array_values(array_filter(['tls_cert', 'server_name', 'public_key', 'short_id'], function ($field) use ($tls) {
        return array_key_exists($field, $tls);
    })),
    'raw_json_contains_secret_markers' => (bool)preg_match('/SECRET-/', json_encode($servers)),
];
fixture_emit($report);
