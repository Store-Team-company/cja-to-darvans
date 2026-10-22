<?php
/**
 * Data Pilot SiteGround setup wizard.
 *
 * The distribution generator creates a random, one-use installation code.
 * The browser receives no Laravel secrets, and this entrypoint is locked
 * after a successful install. Never put the Laravel application inside
 * the public web root.
 */
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");

function dpE(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dpRoot(): string
{
    foreach ([__DIR__.'/../data-pilot-app', __DIR__.'/..'] as $path) {
        $real = realpath($path);
        if ($real && is_file($real.'/bootstrap/app.php') && is_file($real.'/composer.json')) {
            return $real;
        }
    }
    http_response_code(503);
    exit('Data Pilot application files are missing. Upload the complete SiteGround package.');
}

function dpRequirements(string $root): array
{
    $requirements = [
        'PHP 8.2 or newer' => PHP_VERSION_ID >= 80200,
        'Composer dependencies installed (vendor/)' => is_file($root.'/vendor/autoload.php'),
    ];

    foreach (['openssl', 'pdo_mysql', 'mbstring', 'xml', 'ctype', 'tokenizer', 'fileinfo', 'zip', 'json'] as $extension) {
        $requirements['PHP extension: '.$extension] = extension_loaded($extension);
    }

    foreach ([
        $root, $root.'/storage/app', $root.'/storage/logs',
        $root.'/storage/framework/cache/data', $root.'/storage/framework/sessions',
        $root.'/storage/framework/views', $root.'/bootstrap/cache',
    ] as $folder) {
        $requirements['Writable directory: '.str_replace($root, 'application', $folder)] =
            is_dir($folder) && is_writable($folder);
    }

    return $requirements;
}

function dpEnvQuote(string $value): string
{
    // All user-supplied text variables are validated; DB password is
    // encoded separately to preserve all special characters without escaping.
    return '"'.str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value).'"';
}

function dpEnvSet(string $text, array $items): string
{
    foreach ($items as $key => $value) {
        $assignment = $key.'='.dpEnvQuote((string) $value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $text = preg_match($pattern, $text)
            ? (string) preg_replace($pattern, str_replace('\\', '\\\\', $assignment), $text, 1)
            : rtrim($text)."\n".$assignment."\n";
    }

    return rtrim($text)."\n";
}

function dpEnvRead(string $env, string $key): ?string
{
    if (preg_match('/^'.preg_quote($key, '/').'=([^\r\n]*)$/m', $env, $match)) {
        return trim($match[1], '"');
    }

    return null;
}

function dpIsHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function dpValidateInput(array $data): array
{
    $url = trim((string) ($data['app_url'] ?? ''));
    $parsed = parse_url($url);
    if (!is_array($parsed) || strtolower((string) ($parsed['scheme'] ?? '')) !== 'https'
        || empty($parsed['host']) || isset($parsed['user']) || isset($parsed['pass'])
        || isset($parsed['path']) || isset($parsed['query']) || isset($parsed['fragment'])
        || !filter_var($parsed['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        throw new InvalidArgumentException('Enter your HTTPS website URL without a trailing slash or path.');
    }

    $host = trim((string) ($data['db_host'] ?? ''));
    $database = trim((string) ($data['db_database'] ?? ''));
    $username = trim((string) ($data['db_username'] ?? ''));
    $dbPassword = (string) ($data['db_password'] ?? '');
    $port = filter_var($data['db_port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

    if (!preg_match('/^[A-Za-z0-9.:-]{1,190}$/', $host) || !$port
        || !preg_match('/^[A-Za-z0-9_$-]{1,100}$/', $database)
        || !preg_match('/^[A-Za-z0-9_$-]{1,100}$/', $username)
        || $dbPassword === '') {
        throw new InvalidArgumentException('Check the SiteGround MySQL host, port, database, username and password.');
    }

    $adminUsername = trim((string) ($data['admin_username'] ?? ''));
    $adminPassword = (string) ($data['admin_password'] ?? '');
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{2,39}$/', $adminUsername)) {
        throw new InvalidArgumentException('Admin username must be 3-40 letters, numbers or simple punctuation.');
    }
    if (strlen($adminPassword) < 12 || $adminPassword !== (string) ($data['admin_password_confirmation'] ?? '')) {
        throw new InvalidArgumentException('Admin password must have 12+ characters and both password fields must match.');
    }

    $appName = trim((string) ($data['app_name'] ?? 'Data Pilot'));
    if (!preg_match('/^[A-Za-z0-9 ._-]{2,80}$/', $appName)) {
        throw new InvalidArgumentException('Application name must be 2-80 letters, numbers or spaces.');
    }

    return [
        'app_url' => $url,
        'app_name' => $appName,
        'db_host' => $host,
        'db_port' => (string) $port,
        'db_database' => $database,
        'db_username' => $username,
        'db_password' => $dbPassword,
        'admin_username' => $adminUsername,
        'admin_password' => $adminPassword,
    ];
}

function dpInstall(string $root, array $data): void
{
    $lock = $root.'/storage/app/.siteground-installed';
    $pending = $root.'/storage/app/.siteground-install-pending';
    $envFile = $root.'/.env';
    $existing = is_file($envFile) ? (string) file_get_contents($envFile) : null;

    if (is_file($lock) || ($existing !== null && !is_file($pending))) {
        throw new RuntimeException('This application is already configured. Installation is disabled.');
    }

    // Verify database connection BEFORE creating the application environment.
    try {
        $pdo = new PDO(
            'mysql:host='.$data['db_host'].';port='.$data['db_port'].';dbname='.$data['db_database'].';charset=utf8mb4',
            $data['db_username'], $data['db_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
        );
        $pdo->query('SELECT 1')->closeCursor();
    } catch (PDOException $e) {
        throw new RuntimeException('MySQL connection failed. Check the database and user in SiteGround Site Tools.', 0, $e);
    }

    $template = $existing ?? (string) file_get_contents($root.'/.env.example');
    if ($template === '') {
        throw new RuntimeException('Missing application .env.example template.');
    }

    $appKey = dpEnvRead($template, 'APP_KEY');
    if (!$existing || !$appKey) {
        $appKey = 'base64:'.base64_encode(random_bytes(32));
    }

    $consumerKey = $existing ? dpEnvRead($existing, 'BDP_WC_CONSUMER_KEY') : null;
    $consumerSecret = $existing ? dpEnvRead($existing, 'BDP_WC_CONSUMER_SECRET') : null;

    $items = [
        'APP_NAME' => $data['app_name'],
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_KEY' => $appKey,
        'APP_URL' => $data['app_url'],
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => $data['db_host'],
        'DB_PORT' => $data['db_port'],
        'DB_DATABASE' => $data['db_database'],
        'DB_USERNAME' => $data['db_username'],
        'DB_PASSWORD' => '',
        'DB_PASSWORD_BASE64' => base64_encode($data['db_password']),
        'CACHE_DRIVER' => 'file',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'file',
        'SESSION_SECURE_COOKIE' => 'true',
        'BDP_LOGIN_USERNAME' => $data['admin_username'],
        'BDP_LOGIN_PASSWORD' => '',
        'BDP_LOGIN_PASSWORD_HASH' => password_hash($data['admin_password'], PASSWORD_BCRYPT),
        'BDP_ADMIN_USERNAME' => $data['admin_username'],
        'BDP_WC_CONSUMER_KEY' => $consumerKey ?: 'ck_'.bin2hex(random_bytes(32)),
        'BDP_WC_CONSUMER_SECRET' => $consumerSecret ?: 'cs_'.bin2hex(random_bytes(32)),
    ];
    $env = dpEnvSet($template, $items);

    // The pending marker enables a safe retry if MySQL migrations fail. It is
    // never supplied in URL or exposed outside the private application folder.
    if (!is_file($pending) && file_put_contents($pending, 'pending', LOCK_EX) === false) {
        throw new RuntimeException('Cannot write installation state to storage/app.');
    }
    $temporary = $root.'/.env.setup-'.bin2hex(random_bytes(8));
    if (file_put_contents($temporary, $env, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write Laravel .env. Check private application folder permissions.');
    }
    @chmod($temporary, 0600);
    if (!rename($temporary, $envFile)) {
        @unlink($temporary);
        throw new RuntimeException('Cannot activate Laravel .env.');
    }

    // Run official Laravel migrations through its console kernel, without
    // shell_exec, SSH, artisan on the web, or publishing an admin endpoint.
    require_once $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    if ($kernel->call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
        throw new RuntimeException('Database migrations could not complete. Correct the problem and retry.');
    }

    // Make the admin profile and the login credentials agree on first install.
    if (Illuminate\Support\Facades\Schema::hasTable('bdp_admin_profiles')) {
        Illuminate\Support\Facades\DB::table('bdp_admin_profiles')->updateOrInsert(
            ['id' => 1],
            ['name' => $data['app_name'].' Admin', 'username' => $data['admin_username'],
             'password' => $items['BDP_LOGIN_PASSWORD_HASH'], 'updated_at' => now(), 'created_at' => now()]
        );
    }

    $public = realpath(__DIR__) ?: __DIR__;
    $storageTarget = $root.'/storage/app/public';
    if (!is_dir($storageTarget)) {
        @mkdir($storageTarget, 0775, true);
    }
    if (!file_exists($public.'/storage')) {
        @symlink($storageTarget, $public.'/storage');
    }
    if (!is_dir($public.'/storage')) {
        // Some shared hosts disallow symlinks. CJ embedded image imports may
        // still write to this accessible fallback folder.
        @mkdir($public.'/storage/balenco-data-pilot/cja-images', 0775, true);
    }

    if (file_put_contents($lock, gmdate('c')."\n", LOCK_EX) === false) {
        throw new RuntimeException('Migrations succeeded, but the installation lock could not be written.');
    }
    @chmod($lock, 0640);
    @unlink($root.'/storage/app/.siteground-install-key');
    @unlink($pending);
}

$root = dpRoot();
$installed = $root.'/storage/app/.siteground-installed';
$pending = $root.'/storage/app/.siteground-install-pending';
$envFile = $root.'/.env';
$keyFile = $root.'/storage/app/.siteground-install-key';

if (is_file($installed) || (is_file($envFile) && !is_file($pending))) {
    http_response_code(410);
    exit('Data Pilot is already installed. The setup wizard is permanently disabled.');
}
if (!is_file($keyFile)) {
    http_response_code(503);
    exit('Setup code is missing. Use the SiteGround distribution ZIP generated by GitHub Actions.');
}
if (!dpIsHttps()) {
    http_response_code(403);
    exit('For security, install Data Pilot only over HTTPS. Enable SSL in SiteGround Site Tools.');
}

session_name('datapilot_installer');
session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Strict', 'path' => '/']);
session_start();
if (empty($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
}

$requirements = dpRequirements($root);
$ready = !in_array(false, $requirements, true);
$authenticated = ($_SESSION['siteground_setup_ok'] ?? false) === true;
$error = null;
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['setup_csrf'], (string) ($_POST['_csrf'] ?? ''))) {
        $error = 'Session expired. Reload this page and try again.';
    } elseif (!$authenticated && ($_POST['step'] ?? '') === 'unlock') {
        $attempt = (string) ($_POST['installation_code'] ?? '');
        $expected = trim((string) file_get_contents($keyFile));
        if (strlen($attempt) === 64 && hash_equals($expected, hash('sha256', $attempt))) {
            session_regenerate_id(true);
            $_SESSION['siteground_setup_ok'] = true;
            $authenticated = true;
        } else {
            sleep(1);
            $error = 'Invalid installation code. Find INSTALLATION-CODE.txt in the private ZIP package.';
        }
    } elseif ($authenticated && ($_POST['step'] ?? '') === 'install') {
        if (!$ready) {
            $error = 'Resolve the failing server requirements below before installation.';
        } else {
            try {
                $data = dpValidateInput($_POST);
                dpInstall($root, $data);
                $done = true;
                $_SESSION = [];
                session_destroy();
            } catch (Throwable $e) {
                error_log('[DataPilot installer] '.$e->getMessage());
                $error = $e->getMessage() instanceof Stringable ? 'Installation failed.' : $e->getMessage();
            }
        }
    }
}
$defaultUrl = 'https://'.preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'your-domain.com'));
$val = static fn (string $key, string $default = ''): string => dpE((string) ($_POST[$key] ?? $default));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install Data Pilot | SiteGround</title>
<style>
*{box-sizing:border-box}body{background:#f4f6fa;color:#18243a;font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;margin:0;padding:32px 16px}.wrap{max-width:780px;margin:auto}.mast{display:flex;align-items:center;gap:13px;margin-bottom:25px}.mark{display:grid;place-items:center;background:#ed6c30;color:#fff;font-weight:800;border-radius:13px;width:45px;height:45px;font-size:20px}.eyebrow{margin:0;font-size:12px;color:#66758b;text-transform:uppercase;letter-spacing:.12em;font-weight:800}h1{font-size:27px;line-height:1.2;margin:2px 0 0;letter-spacing:-.03em}.panel{background:white;border:1px solid #e0e6f0;border-radius:16px;box-shadow:0 4px 24px #121e3c09;padding:27px;margin:18px 0}.panel h2{margin:0 0 7px;font-size:20px}.hint{color:#65748a;margin:0 0 20px}.step{font-size:12px;letter-spacing:.08em;color:#d55a28;text-transform:uppercase;font-weight:900}.grid{display:grid;grid-template-columns:1fr 1fr;gap:17px}.full{grid-column:1/-1}label{display:block;font-weight:650;font-size:13px;margin-bottom:6px}input{display:block;width:100%;border:1px solid #cad5e4;border-radius:9px;padding:12px;font:inherit;background:#fff;color:#17243d;outline-offset:3px}input:focus{border-color:#dc642e;outline:2px solid #f9d6c4}button{padding:12px 21px;background:#e9672b;color:#fff;font:inherit;font-weight:750;border:0;border-radius:9px;cursor:pointer}button:disabled{background:#afbac8;cursor:not-allowed}.alert{padding:14px 17px;border-radius:11px;background:#fff5f0;color:#a84018;border:1px solid #f7c7b2;margin:14px 0}.checks{display:grid;grid-template-columns:1fr 1fr;gap:8px}.check{border:1px solid #e3eaf4;border-radius:9px;padding:9px 12px;font-size:12px;display:flex;gap:7px;align-items:start}.ok{color:#147d55;font-weight:900}.fail{color:#ba2534;font-weight:900}.success{background:#eefaf3;border:1px solid #ade0c3;padding:22px;border-radius:12px}small{display:block;color:#6b788d;margin-top:8px}.foot{font-size:12px;color:#778397;margin:25px 0;text-align:center}@media(max-width:600px){.grid,.checks{grid-template-columns:1fr}.panel{padding:20px}h1{font-size:23px}}
</style>
</head>
<body><div class="wrap">
<div class="mast"><div class="mark">DP</div><div><p class="eyebrow">SiteGround installer</p><h1>Set up Data Pilot</h1></div></div>
<?php if ($done): ?>
<div class="success"><h2>Installation complete</h2>
<p>Database tables and secure admin credentials are configured. The installation code has been disabled.</p>
<p><a href="/" rel="nofollow">Open the application</a> and sign in with the admin credentials you chose.</p>
<p><strong>Next:</strong> Go to CJ Settings, save your CJ API key, test the connection, then import your first product.</p>
<p>Delete <code>INSTALLATION-CODE.txt</code> and <code>public_html/setup.php</code> from the server when convenient.</p></div>
<?php else: ?>
<?php if ($error): ?><div class="alert" role="alert"><?=dpE($error)?></div><?php endif; ?>
<?php if (!$authenticated): ?>
<div class="panel">
<p class="step">Step 1 / 3</p><h2>Unlock installation</h2>
<p class="hint">Open <strong>INSTALLATION-CODE.txt</strong> from the downloaded deployment ZIP. The code is unique to this build and works only until installation completes.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="_csrf" value="<?=dpE((string) $_SESSION['setup_csrf'])?>">
<input type="hidden" name="step" value="unlock">
<div class="grid"><div class="full"><label for="installation_code">Installation code</label><input name="installation_code" id="installation_code" type="password" required autocomplete="off" minlength="64" maxlength="64" placeholder="Paste your 64-character code"></div>
<div class="full"><button type="submit">Continue to setup</button></div></div></form></div>
<?php else: ?>
<div class="panel"><p class="step">Step 2 / 3</p><h2>Server readiness</h2>
<p class="hint">A check must pass before the site can be installed. PHP version: <?=dpE(PHP_VERSION)?></p>
<div class="checks">
<?php foreach ($requirements as $label => $passed): ?>
<div class="check"><span class="<?=$passed?'ok':'fail'?>"><?=$passed?'✓':'✕'?></span><?=dpE($label)?></div>
<?php endforeach; ?>
</div></div>
<div class="panel"><p class="step">Step 3 / 3</p><h2>Website &amp; database</h2>
<p class="hint">Create a MySQL database/user in SiteGround Site Tools before completing this form. Your password is saved in the private Laravel application folder, never in public_html.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="_csrf" value="<?=dpE((string) $_SESSION['setup_csrf'])?>">
<input type="hidden" name="step" value="install">
<div class="grid">
<div><label>Application name</label><input required name="app_name" value="<?=$val('app_name','Data Pilot')?>" maxlength="80"></div>
<div><label>HTTPS website URL</label><input type="url" required name="app_url" value="<?=$val('app_url',$defaultUrl)?>"></div>
<div><label>Database host</label><input required name="db_host" value="<?=$val('db_host','localhost')?>"></div>
<div><label>Database port</label><input required type="number" min="1" max="65535" name="db_port" value="<?=$val('db_port','3306')?>"></div>
<div><label>Database name</label><input required name="db_database" value="<?=$val('db_database')?>" autocomplete="off"></div>
<div><label>Database username</label><input required name="db_username" value="<?=$val('db_username')?>" autocomplete="off"></div>
<div class="full"><label>Database password</label><input required type="password" name="db_password" autocomplete="new-password"><small>Copied from Site Tools → Site → MySQL.</small></div>
<div class="full"><label>Admin username</label><input required name="admin_username" value="<?=$val('admin_username','admin')?>" minlength="3" maxlength="40"></div>
<div><label>Admin password (12+ characters)</label><input required type="password" name="admin_password" minlength="12" autocomplete="new-password"></div>
<div><label>Confirm admin password</label><input required type="password" name="admin_password_confirmation" minlength="12" autocomplete="new-password"></div>
<div class="full"><button type="submit" <?=$ready?'':'disabled'?>>Install Data Pilot</button>
<small>Creates Laravel configuration, verifies MySQL, runs migrations and permanently locks the installer. Existing databases are not erased.</small></div></div></form></div>
<?php endif; ?>
<?php endif; ?>
<div class="foot">Private application files remain outside public_html · HTTPS required · No SSH needed when using the packaged ZIP</div>
</div></body></html>
