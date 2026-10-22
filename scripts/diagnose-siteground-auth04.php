<?php
/**
 * Data Pilot AUTH-04: read-only, secret-safe SiteGround mail + OTP diagnosis.
 *
 * Usage (from existing PRIVATE Data Pilot installation):
 *   php scripts/diagnose-siteground-auth04.php
 *   php scripts/diagnose-siteground-auth04.php --probe-network
 *   php scripts/diagnose-siteground-auth04.php --account
 *
 * Never sends an OTP, changes the database or prints SMTP credentials.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(dirname(__DIR__));
if (! $root || ! is_file($root.'/.env')
    || ! is_file($root.'/vendor/autoload.php')
    || ! is_file($root.'/bootstrap/app.php')) {
    fwrite(STDERR, "AUTH-04: Run from a complete, existing PRIVATE Data Pilot installation.\n");
    exit(2);
}

require_once $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfg = (array) config('mail', []);
$driver = (string) ($cfg['default'] ?? '');
$smtp = (array) ($cfg['mailers']['smtp'] ?? []);
$host = (string) ($smtp['host'] ?? '');
$port = $smtp['port'] ?? null;
$rawEnv = (string) file_get_contents($root.'/.env');
$envValue = static function (string $name) use ($rawEnv): string {
    if (! preg_match('/^'.preg_quote($name, '/').'\s*=\s*(.*)\s*$/m', $rawEnv, $match)) {
        return '';
    }

    return trim(trim($match[1]), " \t\n\r\0\x0B\"'");
};

echo "DATA PILOT AUTH-04 SAFE DIAGNOSIS\n";
echo 'Laravel version: '.app()->version()."\n";
echo 'Config cached: '.(is_file($root.'/bootstrap/cache/config.php') ? 'YES' : 'NO')."\n";
echo 'Effective mailer: '.$driver."\n";
echo 'From address configured: '.(filter_var($cfg['from']['address'] ?? '', FILTER_VALIDATE_EMAIL) ? 'YES' : 'NO')."\n";
echo 'SMTP username set: '.(trim((string) ($smtp['username'] ?? '')) !== '' ? 'YES' : 'NO')."\n";
echo 'SMTP password set: '.((string) ($smtp['password'] ?? '') !== '' ? 'YES' : 'NO')."\n";
echo 'MAIL_URL configured: '.((string) ($smtp['url'] ?? '') !== '' ? 'YES' : 'NO')."\n";
echo 'MAIL_SCHEME in .env: '.($envValue('MAIL_SCHEME') !== '' ? 'SET (check config/mail.php support)' : 'UNSET')."\n";

$url = (string) ($smtp['url'] ?? '');
if ($url !== '') {
    try {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?? $port;
    } catch (\Throwable) {
        echo "Mail URL syntax: INVALID (contents hidden)\n";
    }
}

// Optional read-only comparison with a provider-approved outgoing hostname.
// The server configuration is never changed by this diagnostic.
$probeHost = $host;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--probe-host=')) {
        $candidate = substr($arg, strlen('--probe-host='));
        if (! $candidate
            || filter_var($candidate, FILTER_VALIDATE_IP)
            || ! filter_var($candidate, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || ! str_contains($candidate, '.')) {
            fwrite(STDERR, "AUTH-04: Invalid candidate hostname.\n");
            exit(2);
        }
        $probeHost = $candidate;
    }
}

$validPort = is_scalar($port) && ctype_digit((string) $port)
    && (int) $port >= 1 && (int) $port <= 65535;

echo 'Effective SMTP port: '.($validPort ? (string) $port : 'INVALID OR MISSING')."\n";
$dotenvPort = $envValue('MAIL_PORT');
if ($dotenvPort !== '') {
    $rawValid = ctype_digit($dotenvPort) && (int) $dotenvPort >= 1 && (int) $dotenvPort <= 65535;
    echo 'Private .env MAIL_PORT: '.($rawValid ? 'VALID' : 'INVALID')."\n";
    echo 'Port differs from effective config: '.((string) $port !== $dotenvPort ? 'YES (cache or MAIL_URL override possible)' : 'NO')."\n";
} else {
    echo "Private .env MAIL_PORT: NOT SET\n";
}
echo 'Effective SMTP hostname configured: '.($host !== '' ? 'YES' : 'NO')."\n";
echo 'Effective SMTP encryption mode: '.(string) ($smtp['encryption'] ?? '(none)')."\n";

if ($driver === 'log' || $driver === 'array') {
    echo "STOP: mailer does not deliver real OTP emails.\n";
}
if ($driver === 'failover' && array_intersect(
    (array) ($cfg['mailers']['failover']['mailers'] ?? []), ['log', 'array']
)) {
    echo "STOP: failover can silently accept OTP emails into a log/array sink.\n";
}
if ($driver === 'smtp' && ! $validPort) {
    echo "STOP: SMTP port outside valid 1..65535 range; correct private .env and clear config cache.\n";
}
if ($probeHost !== '' && $driver === 'smtp') {
    echo 'Probe using configured SMTP host: '.($probeHost === $host ? 'YES' : 'NO (read-only candidate)')."\n";
    echo 'SMTP DNS resolves: '.(gethostbynamel($probeHost) ? 'YES' : 'NO')."\n";
    if (in_array('--probe-network', $argv, true) && $validPort) {
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client('tcp://'.$probeHost.':'.$port, $errno, $error, 6);
        echo 'TCP socket connects: '.(is_resource($socket) ? 'YES' : 'NO')."\n";
        if (is_resource($socket)) {
            fclose($socket);
        }

        // PHP enforces certificate trust and hostname matching. Do not
        // disable verify_peer, verify_peer_name or allow_self_signed.
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $probeHost,
            'SNI_enabled' => true,
        ]]);

        if (in_array(strtolower((string) ($smtp['encryption'] ?? '')), ['ssl', 'smtps'], true)) {
            $secure = @stream_socket_client(
                'ssl://'.$probeHost.':'.$port,
                $tlsErrno, $tlsError, 8, STREAM_CLIENT_CONNECT, $context
            );
            echo 'Verified SMTP implicit TLS handshake: '.(is_resource($secure) ? 'PASS' : 'FAIL')."\n";
            if (is_resource($secure)) {
                fclose($secure);
            }
        } elseif (strtolower((string) ($smtp['encryption'] ?? '')) === 'tls') {
            // STARTTLS: read the server's greeting and capability response,
            // then request TLS. Never attempt AUTH or transmit an OTP.
            $conn = @stream_socket_client(
                'tcp://'.$probeHost.':'.$port, $tcpErrno, $tcpError, 8,
                STREAM_CLIENT_CONNECT, $context
            );
            $valid = false;
            if (is_resource($conn)) {
                stream_set_timeout($conn, 5);
                $readReply = static function ($stream): string {
                    $response = '';
                    for ($n = 0; $n < 30; $n++) {
                        $line = fgets($stream, 1024);
                        if ($line === false) {
                            return '';
                        }
                        $response .= $line;
                        if (preg_match('/^[0-9]{3} /', $line)) {
                            return $response;
                        }
                    }

                    return '';
                };
                $welcome = $readReply($conn);
                if (str_starts_with($welcome, '220')) {
                    fwrite($conn, "EHLO datapilot.local\r\n");
                    $ehlo = $readReply($conn);
                    if (str_starts_with($ehlo, '250') && stripos($ehlo, 'STARTTLS') !== false) {
                        fwrite($conn, "STARTTLS\r\n");
                        if (str_starts_with($readReply($conn), '220')) {
                            $valid = @stream_socket_enable_crypto(
                                $conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT
                            ) === true;
                        }
                    }
                }
                fclose($conn);
            }
            echo 'Verified SMTP STARTTLS handshake: '.($valid ? 'PASS' : 'FAIL')."\n";
        } else {
            echo "SMTP TLS: NOT TESTED (provider encryption mode must be confirmed).\n";
        }

        echo "Probe never authenticates, sends email or proves mailbox delivery.\n";
        echo "Use only the provider-approved outgoing hostname in private .env.\n";
    }
}

$migrationName = '2026_10_22_000003_create_saas_email_otp_challenges_table';
try {
    $hasTable = \Illuminate\Support\Facades\Schema::hasTable('saas_email_otp_challenges');
    $migrationApplied = \Illuminate\Support\Facades\Schema::hasTable('migrations')
        && \Illuminate\Support\Facades\DB::table('migrations')
            ->where('migration', $migrationName)->exists();
    echo 'OTP table present: '.($hasTable ? 'YES' : 'NO')."\n";
    echo 'AUTH-03 migration applied: '.($migrationApplied ? 'YES' : 'NO')."\n";

    if (in_array('--account', $argv, true)) {
        echo 'Enter account email privately (not logged or printed in report): ';
        $email = mb_strtolower(trim((string) fgets(STDIN)));
        $account = \App\Models\User::where('email', $email)->first();
        unset($email);
        echo 'Account exists: '.($account ? 'YES' : 'NO')."\n";
        if ($account) {
            echo 'Internal account reference: '.(int) $account->id."\n";
            $eligible = \Illuminate\Support\Facades\DB::table('saas_workspace_memberships as m')
                ->join('saas_workspaces as w', 'w.id', '=', 'm.workspace_id')
                ->where('m.user_id', $account->id)
                ->where('m.status', 'active')
                ->where('w.status', '!=', 'suspended')
                ->exists();
            echo 'Accessible workspace membership: '.($eligible ? 'YES' : 'NO')."\n";
            if ($hasTable) {
                $last = \Illuminate\Support\Facades\DB::table('saas_email_otp_challenges')
                    ->where('user_id', $account->id)->orderByDesc('id')
                    ->first(['sent_at', 'expires_at', 'consumed_at', 'attempts']);
                echo 'OTP challenge exists: '.($last ? 'YES' : 'NO')."\n";
                if ($last) {
                    echo 'Latest challenge handoff timestamp exists: '.($last->sent_at ? 'YES' : 'NO')."\n";
                    echo 'Latest challenge consumed: '.($last->consumed_at ? 'YES' : 'NO')."\n";
                    echo 'Incorrect attempts: '.(int) $last->attempts."\n";
                }
            }
        }
    }
} catch (\Throwable $e) {
    echo 'Database/auth inspection could not complete (error class only): '.get_class($e)."\n";
}

echo "IMPORTANT: A successful SMTP handoff does NOT prove mailbox delivery.\n";
