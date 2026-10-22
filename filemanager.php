<?php
declare(strict_types=1);

/**
 * Enterprise File Manager Pro
 * Ultra-fast, secure, responsive single-file web file manager
 * Powered by bywZero
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__FILE__, true);
}

if (!headers_sent()) {
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// Quick diagnostic endpoint to verify active version on server
if (isset($_GET['v']) && $_GET['v'] === 'check') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'app' => 'File Manager Pro',
        'version' => '2.0.0-PRO',
        'build' => '2026-10-22',
        'file_size' => filesize(__FILE__),
        'file_mtime' => date('Y-m-d H:i:s', filemtime(__FILE__)),
        'php_version' => PHP_VERSION,
        'opcache_active' => function_exists('opcache_get_status') && !empty(opcache_get_status(false)['opcache_enabled']),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$sessionDir = sys_get_temp_dir() . '/tinnycentre-fm-sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0700, true);
}
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
}
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

const APP_NAME = 'File Manager Pro';
$baseDir = realpath(getenv('FILEMANAGER_BASE_DIR') ?: __DIR__) ?: __DIR__;
$password = getenv('FILEMANAGER_PASSWORD') ?: '010286';
$terminalUrl = getenv('FILEMANAGER_TERMINAL_URL') ?: '';
$terminalBypassToken = getenv('FILEMANAGER_TERMINAL_TOKEN') ?: '53109730356104db67d2e0bafae31dd88c169eb92dc2247c';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function check_login_rate_limit(): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $file = sys_get_temp_dir() . '/fm_limit_' . md5($ip) . '.json';
    if (file_exists($file)) {
        $data = @json_decode(file_get_contents($file), true) ?: [];
        if (!empty($data['lock_until']) && time() < $data['lock_until']) {
            return false;
        }
    }
    return true;
}

function record_failed_login(): int {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $file = sys_get_temp_dir() . '/fm_limit_' . md5($ip) . '.json';
    $data = file_exists($file) ? (@json_decode(file_get_contents($file), true) ?: []) : [];
    $attempts = (int)($data['attempts'] ?? 0) + 1;
    $lockUntil = 0;
    if ($attempts >= 5) {
        $lockUntil = time() + 60;
    }
    @file_put_contents($file, json_encode(['attempts' => $attempts, 'lock_until' => $lockUntil, 'time' => time()]));
    return $lockUntil ? ($lockUntil - time()) : 0;
}

function reset_failed_login(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $file = sys_get_temp_dir() . '/fm_limit_' . md5($ip) . '.json';
    if (file_exists($file)) {
        @unlink($file);
    }
}

function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function check_token(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $submitted = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$submitted)) {
            if (is_ajax()) {
                json_response(['success' => false, 'error' => 'CSRF token inválido o expirado'], 403);
            }
            http_response_code(403);
            exit('CSRF token inválido o expirado.');
        }
    }
}

function is_ajax(): bool {
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (!empty($_POST['ajax']) || !empty($_GET['ajax']))
        || (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json'));
}

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function bytes_h(float $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return sprintf($i ? '%.1f %s' : '%.0f %s', $bytes, $units[$i]);
}

function normalize_rel(string $path): string {
    $path = str_replace('\\', '/', $path);
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    return implode('/', $parts);
}

function full_path(string $rel, string $base): string {
    $rel = normalize_rel($rel);
    return rtrim($base, DIRECTORY_SEPARATOR) . ($rel === '' ? '' : DIRECTORY_SEPARATOR . $rel);
}

function assert_inside(string $path, string $base): void {
    $baseNorm = rtrim(str_replace('\\', '/', realpath($base) ?: $base), '/') . '/';
    $real = realpath($path);
    if ($real === false) {
        $real = realpath(dirname($path));
    }
    if ($real === false) {
        if (is_ajax()) json_response(['success' => false, 'error' => 'Ruta no encontrada o inaccesible'], 403);
        http_response_code(403);
        exit('Ruta fuera del directorio permitido');
    }
    $realNorm = rtrim(str_replace('\\', '/', $real), '/') . '/';
    if (strpos($realNorm, $baseNorm) !== 0 && $realNorm !== $baseNorm) {
        if (is_ajax()) json_response(['success' => false, 'error' => 'Acceso denegado: ruta restringida'], 403);
        http_response_code(403);
        exit('Acceso denegado: ruta restringida');
    }
}

function rel_from_full(string $path, string $base): string {
    $path = str_replace('\\', '/', $path);
    $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
    return normalize_rel(str_starts_with($path, $base) ? substr($path, strlen($base)) : '');
}

function perms_h(string $path): string {
    $perms = @fileperms($path);
    return $perms === false ? '----' : substr(sprintf('%o', $perms), -4);
}

function octal_perms(string $path): string {
    $perms = @fileperms($path);
    return $perms === false ? '0644' : sprintf('%04o', $perms & 0777);
}

function rrmdir(string $path): bool {
    if (is_link($path) || is_file($path)) {
        return @unlink($path);
    }
    if (!is_dir($path)) {
        return false;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $sub = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($sub) && !is_link($sub)) {
            if (!rrmdir($sub)) return false;
        } else {
            if (!@unlink($sub)) return false;
        }
    }
    return @rmdir($path);
}

function copy_recursive(string $src, string $dst): bool {
    if (is_link($src)) {
        $target = readlink($src);
        return $target !== false && @symlink($target, $dst);
    }
    if (is_file($src)) {
        return @copy($src, $dst);
    }
    if (!is_dir($src)) {
        return false;
    }
    if (!is_dir($dst) && !@mkdir($dst, 0775, true)) {
        return false;
    }
    foreach (scandir($src) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (!copy_recursive($src . DIRECTORY_SEPARATOR . $item, $dst . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }
    return true;
}

function chmod_recursive(string $path, int $mode): bool {
    if (!@chmod($path, $mode)) {
        return false;
    }
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            chmod_recursive($path . DIRECTORY_SEPARATOR . $item, $mode);
        }
    }
    return true;
}

function command_path(string $name): ?string {
    $out = [];
    $code = 1;
    @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $out, $code);
    return $code === 0 && !empty($out[0]) ? trim($out[0]) : null;
}

function run_command(string $cmd, string &$output = ''): bool {
    $out = [];
    $code = 1;
    @exec($cmd . ' 2>&1', $out, $code);
    $output = trim(implode("\n", $out));
    return $code === 0;
}

function zip_entry_safe(string $entry): bool {
    $entry = str_replace('\\', '/', $entry);
    return $entry !== '' && $entry[0] !== '/' && !str_contains($entry, '../') && !str_starts_with($entry, '..');
}

function assert_zip_entries_inside(string $zipFile, string $destDir, string $baseDir): void {
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'] ?? '';
                if (!zip_entry_safe($name)) {
                    $zip->close();
                    throw new RuntimeException('El ZIP contiene rutas inseguras o no permitidas.');
                }
                assert_inside(rtrim($destDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name, $baseDir);
            }
            $zip->close();
            return;
        }
    }
    $unzip = command_path('unzip');
    if ($unzip) {
        $out = [];
        $code = 1;
        @exec(escapeshellarg($unzip) . ' -Z1 ' . escapeshellarg($zipFile) . ' 2>/dev/null', $out, $code);
        if ($code === 0) {
            foreach ($out as $entry) {
                if (!zip_entry_safe($entry)) {
                    throw new RuntimeException('El ZIP contiene rutas inseguras: ' . $entry);
                }
                assert_inside(rtrim($destDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $entry, $baseDir);
            }
            return;
        }
    }
    throw new RuntimeException('No se pudo verificar la seguridad del archivo ZIP.');
}

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = [$type, $message];
}

function redirect_here(string $dir): never {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? 'filemanager.php');
    header('Location: ' . $script . '?p=' . rawurlencode(normalize_rel($dir)));
    exit;
}

function file_category(string $path): string {
    if (is_dir($path)) return 'folder';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'php', 'js', 'jsx', 'ts', 'tsx', 'html', 'htm', 'css', 'scss', 'sass', 'json', 'xml', 'sql', 'sh', 'bash', 'py', 'java', 'c', 'cpp', 'h', 'hpp', 'cs', 'go', 'rs', 'kt', 'rb', 'lua', 'yaml', 'yml', 'env', 'ini', 'conf', 'htaccess' => 'code',
        'txt', 'md', 'log', 'rtf', 'csv', 'tsv' => 'text',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'avif' => 'image',
        'mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv' => 'video',
        'mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac' => 'audio',
        'pdf' => 'pdf',
        'zip', 'tar', 'gz', 'bz2', 'xz', '7z', 'rar', 'iso' => 'archive',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods' => 'document',
        default => 'file',
    };
}

function mime_for_file(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'bmp' => 'image/bmp',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg', 'mov' => 'video/quicktime',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'flac' => 'audio/flac',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain; charset=utf-8', 'md' => 'text/markdown; charset=utf-8',
        'json' => 'application/json; charset=utf-8', 'xml' => 'application/xml; charset=utf-8',
        'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8',
        'sh' => 'text/plain; charset=utf-8', 'py' => 'text/plain; charset=utf-8', 'php' => 'text/plain; charset=utf-8',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    @session_destroy();
    $script = basename($_SERVER['SCRIPT_NAME'] ?? 'filemanager.php');
    header('Location: ' . $script);
    exit;
}

if ($method === 'POST' && isset($_POST['login'])) {
    if (!check_login_rate_limit()) {
        flash('bad', 'Demasiados intentos fallidos. Por favor, espera 60 segundos.');
    } else {
        $input = (string)($_POST['password'] ?? '');
        $ok = false;
        if (str_starts_with($password, '$2y$') || str_starts_with($password, '$argon2')) {
            $ok = password_verify($input, $password);
        } else {
            $ok = hash_equals($password, $input);
        }

        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['fm_auth'] = true;
            reset_failed_login();
            token();
            $script = basename($_SERVER['SCRIPT_NAME'] ?? 'filemanager.php');
            header('Location: ' . $script);
            exit;
        } else {
            $lockSecs = record_failed_login();
            if ($lockSecs > 0) {
                flash('bad', 'Contraseña incorrecta. Has superado el límite de intentos. Bloqueado por 60s.');
            } else {
                flash('bad', 'Contraseña incorrecta.');
            }
        }
    }
}

$authed = !empty($_SESSION['fm_auth']);
if ($authed) {
    setcookie('FM_TTYD', $terminalBypassToken, [
        'expires' => time() + 86400,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if (!$authed):
?>
<!doctype html>
<html lang="es" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo h(APP_NAME); ?> — Iniciar Sesión</title>
<style>
:root{--bg:#090d14;--surface:#121824;--border:#1f293d;--text:#f1f5f9;--subtext:#94a3b8;--primary:#3b82f6;--primary-hover:#2563eb;--danger:#ef4444;--radius:12px;--shadow:0 25px 50px -12px rgba(0,0,0,0.5)}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Ubuntu,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;background:radial-gradient(circle at 50% 20%, #151d2f 0%, var(--bg) 80%);color:var(--text);padding:20px}
.login-card{width:100%;max-width:400px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:36px;box-shadow:var(--shadow);backdrop-filter:blur(10px)}
.brand{display:flex;align-items:center;gap:14px;margin-bottom:28px}
.logo-icon{width:46px;height:46px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);border-radius:10px;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 16px rgba(59,130,246,0.3)}
.logo-icon svg{width:26px;height:26px;fill:none;stroke:#fff;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.brand-text h1{font-size:20px;font-weight:700;letter-spacing:-0.02em}
.brand-text p{font-size:13px;color:var(--subtext)}
.field{margin-bottom:20px;position:relative}
.field label{display:block;font-size:13px;font-weight:500;margin-bottom:8px;color:var(--subtext)}
.input-wrap{position:relative}
.input-wrap input{width:100%;padding:12px 42px 12px 14px;background:#0d121c;border:1px solid var(--border);border-radius:8px;color:#fff;font-size:14px;outline:none;transition:all .2s ease}
.input-wrap input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(59,130,246,0.25)}
.toggle-pwd{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--subtext);cursor:pointer;display:flex;align-items:center;padding:4px}
.toggle-pwd:hover{color:#fff}
.toggle-pwd svg{width:18px;height:18px}
.submit-btn{width:100%;padding:12px;background:var(--primary);color:#fff;font-size:14px;font-weight:600;border:none;border-radius:8px;cursor:pointer;transition:all .2s ease;display:flex;align-items:center;justify-content:center;gap:8px}
.submit-btn:hover{background:var(--primary-hover);transform:translateY(-1px);box-shadow:0 4px 12px rgba(59,130,246,0.35)}
.flash{padding:12px 14px;border-radius:8px;font-size:13px;margin-bottom:20px;display:flex;align-items:center;gap:10px}
.flash.bad{background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3);color:#fca5a5}
.footer{margin-top:24px;text-align:center;font-size:12px;color:#64748b}
</style>
</head>
<body>
<div class="login-card">
    <div class="brand">
        <div class="logo-icon">
            <svg viewBox="0 0 24 24"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>
        </div>
        <div class="brand-text">
            <h1><?php echo h(APP_NAME); ?></h1>
            <p>Acceso seguro al servidor</p>
        </div>
    </div>
    <?php foreach ($_SESSION['flash'] ?? [] as $msg): ?>
        <div class="flash <?php echo h($msg[0]); ?>"><?php echo h($msg[1]); ?></div>
    <?php endforeach; $_SESSION['flash'] = []; ?>
    <form method="post" autocomplete="off">
        <div class="field">
            <label for="password">Contraseña de acceso</label>
            <div class="input-wrap">
                <input type="password" id="password" name="password" placeholder="••••••••••••" autofocus required>
                <button type="button" class="toggle-pwd" id="togglePwd" title="Mostrar contraseña">
                    <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
        </div>
        <button type="submit" name="login" value="1" class="submit-btn">
            <span>Iniciar Sesión</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </button>
    </form>
    <div class="footer">Autonomía Operativa · bywZero Pro</div>
</div>
<script>
const pwd = document.getElementById('password');
const toggle = document.getElementById('togglePwd');
toggle.addEventListener('click', () => {
    const isText = pwd.type === 'text';
    pwd.type = isText ? 'password' : 'text';
    toggle.style.color = isText ? 'var(--subtext)' : '#60a5fa';
});
</script>
</body>
</html>
<?php
exit;
endif;

check_token();

$cwdRel = normalize_rel((string)($_GET['p'] ?? $_POST['p'] ?? ''));
$cwd = full_path($cwdRel, $baseDir);
assert_inside($cwd, $baseDir);
if (!is_dir($cwd)) {
    $cwd = $baseDir;
    $cwdRel = '';
}

if (isset($_GET['raw'])) {
    $rawRel = normalize_rel((string)$_GET['raw']);
    $rawPath = full_path($rawRel, $baseDir);
    assert_inside($rawPath, $baseDir);
    if (!is_file($rawPath) || !is_readable($rawPath)) {
        http_response_code(404);
        exit('Archivo no encontrado');
    }
    $mime = mime_for_file($rawPath);
    $size = filesize($rawPath);

    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    header('Accept-Ranges: bytes');

    if (isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
        if (preg_match('/bytes=\h*(\d+)-(\d*)[\D.*]?/i', $range, $matches)) {
            $start = (int)$matches[1];
            $end = $matches[2] !== '' ? (int)$matches[2] : ($size - 1);
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */$size");
                exit;
            }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
            header("Content-Length: " . ($end - $start + 1));
            $fp = fopen($rawPath, 'rb');
            fseek($fp, $start);
            $chunk = 8192;
            $cur = $start;
            while (!feof($fp) && $cur <= $end) {
                $read = min($chunk, $end - $cur + 1);
                echo fread($fp, $read);
                flush();
                $cur += $read;
            }
            fclose($fp);
            exit;
        }
    }

    header('Content-Length: ' . $size);
    readfile($rawPath);
    exit;
}

if (isset($_GET['download'])) {
    $file = full_path((string)$_GET['download'], $baseDir);
    assert_inside($file, $baseDir);
    if (!is_file($file) || !is_readable($file)) {
        http_response_code(404);
        exit('Archivo no disponible');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-cache, must-revalidate');
    readfile($file);
    exit;
}

if (isset($_GET['zip_folder'])) {
    $folder = full_path((string)$_GET['zip_folder'], $baseDir);
    assert_inside($folder, $baseDir);
    if (!is_dir($folder)) {
        http_response_code(404);
        exit('Carpeta no encontrada');
    }
    $zipName = (basename($folder) ?: 'archive') . '.zip';
    $zipFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fm-dl-' . bin2hex(random_bytes(8)) . '.zip';

    $zip = command_path('zip');
    if ($zip) {
        $cmd = 'cd ' . escapeshellarg($folder) . ' && ' . escapeshellarg($zip) . ' -r -q ' . escapeshellarg($zipFile) . ' .';
        $output = '';
        if (!run_command($cmd, $output)) {
            exit('Error al comprimir: ' . h($output));
        }
    } else {
        exit('Comando zip no disponible en el servidor.');
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($zipFile));
    readfile($zipFile);
    @unlink($zipFile);
    exit;
}

if (isset($_GET['get_content'])) {
    $target = full_path((string)$_GET['get_content'], $baseDir);
    assert_inside($target, $baseDir);
    if (!is_file($target) || !is_readable($target)) {
        json_response(['success' => false, 'error' => 'Archivo no legible o no encontrado'], 404);
    }
    if (filesize($target) > 5 * 1024 * 1024) {
        json_response(['success' => false, 'error' => 'El archivo supera el límite de 5MB para edición'], 400);
    }
    $content = (string)file_get_contents($target);
    json_response([
        'success' => true,
        'name' => basename($target),
        'rel' => rel_from_full($target, $baseDir),
        'size' => bytes_h((float)filesize($target)),
        'mtime' => date('Y-m-d H:i:s', filemtime($target) ?: time()),
        'content' => $content
    ]);
}

if ($method === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'upload') {
            $uploaded = 0;
            $errors = [];
            $files = $_FILES['files'] ?? [];
            if (!empty($files['name']) && is_array($files['name'])) {
                foreach ($files['name'] as $i => $name) {
                    if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    $safeName = basename((string)$name);
                    if ($safeName === '') continue;
                    $target = $cwd . DIRECTORY_SEPARATOR . $safeName;
                    assert_inside($target, $baseDir);
                    if (@move_uploaded_file($files['tmp_name'][$i], $target)) {
                        $uploaded++;
                    } else {
                        $errors[] = $safeName;
                    }
                }
            }
            if ($errors) {
                throw new RuntimeException('No se pudieron subir: ' . implode(', ', $errors));
            }
            if ($uploaded === 0) {
                throw new RuntimeException('No se seleccionaron archivos válidos para subir.');
            }
            if (is_ajax()) {
                json_response(['success' => true, 'message' => "$uploaded archivo(s) subido(s) con éxito."]);
            }
            flash('ok', "$uploaded archivo(s) subido(s) con éxito.");
        } elseif ($action === 'mkdir') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('El nombre de la carpeta no puede estar vacío.');
            $target = $cwd . DIRECTORY_SEPARATOR . basename($name);
            assert_inside($target, $baseDir);
            if (file_exists($target)) throw new RuntimeException('Ya existe un elemento con ese nombre.');
            if (!@mkdir($target, 0775, true)) throw new RuntimeException('No se pudo crear la carpeta.');
            if (is_ajax()) json_response(['success' => true, 'message' => 'Carpeta creada correctamente.']);
            flash('ok', 'Carpeta creada.');
        } elseif ($action === 'newfile') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('El nombre del archivo no puede estar vacío.');
            $target = $cwd . DIRECTORY_SEPARATOR . basename($name);
            assert_inside($target, $baseDir);
            if (file_exists($target)) throw new RuntimeException('El archivo ya existe.');
            if (@file_put_contents($target, '') === false) throw new RuntimeException('No se pudo crear el archivo.');
            if (is_ajax()) json_response(['success' => true, 'message' => 'Archivo creado correctamente.']);
            flash('ok', 'Archivo creado.');
        } elseif ($action === 'save') {
            $target = full_path((string)$_POST['file'], $baseDir);
            assert_inside($target, $baseDir);
            if (!is_file($target)) throw new RuntimeException('El archivo a guardar no existe.');
            if (!is_writable($target)) throw new RuntimeException('El archivo no tiene permisos de escritura.');
            $content = (string)($_POST['content'] ?? '');
            if (@file_put_contents($target, $content) === false) {
                throw new RuntimeException('Error al guardar el archivo en disco.');
            }
            clearstatcache(true, $target);
            if (is_ajax()) {
                json_response([
                    'success' => true,
                    'message' => 'Archivo guardado correctamente.',
                    'size' => bytes_h((float)filesize($target)),
                    'mtime' => date('H:i:s', filemtime($target) ?: time())
                ]);
            }
            flash('ok', 'Archivo guardado.');
        } elseif ($action === 'rename') {
            $src = full_path((string)$_POST['src'], $baseDir);
            assert_inside($src, $baseDir);
            $newName = basename(trim((string)$_POST['name']));
            if ($newName === '') throw new RuntimeException('Nombre inválido.');
            $dst = dirname($src) . DIRECTORY_SEPARATOR . $newName;
            assert_inside($dst, $baseDir);
            if (file_exists($dst) && strtolower($src) !== strtolower($dst)) {
                throw new RuntimeException('Ya existe un archivo o carpeta con ese nombre.');
            }
            if (!@rename($src, $dst)) throw new RuntimeException('No se pudo renombrar.');
            if (is_ajax()) json_response(['success' => true, 'message' => 'Renombrado con éxito.']);
            flash('ok', 'Renombrado.');
        } elseif ($action === 'duplicate') {
            $src = full_path((string)$_POST['src'], $baseDir);
            assert_inside($src, $baseDir);
            if (!file_exists($src)) throw new RuntimeException('El origen no existe.');
            $dir = dirname($src);
            $ext = pathinfo($src, PATHINFO_EXTENSION);
            $baseName = pathinfo($src, PATHINFO_FILENAME);
            $suffix = '_copia';
            $i = 1;
            do {
                $candidate = $dir . DIRECTORY_SEPARATOR . $baseName . $suffix . ($i > 1 ? "_$i" : '') . ($ext ? ".$ext" : '');
                $i++;
            } while (file_exists($candidate));
            assert_inside($candidate, $baseDir);
            $ok = is_dir($src) ? copy_recursive($src, $candidate) : @copy($src, $candidate);
            if (!$ok) throw new RuntimeException('No se pudo duplicar el elemento.');
            if (is_ajax()) json_response(['success' => true, 'message' => 'Elemento duplicado como ' . basename($candidate)]);
            flash('ok', 'Elemento duplicado.');
        } elseif ($action === 'chmod') {
            $src = full_path((string)$_POST['src'], $baseDir);
            assert_inside($src, $baseDir);
            $modeRaw = preg_replace('/[^0-7]/', '', (string)$_POST['mode']) ?: '0644';
            $mode = (int)base_convert($modeRaw, 8, 10);
            $recursive = !empty($_POST['recursive']);
            $ok = $recursive ? chmod_recursive($src, $mode) : @chmod($src, $mode);
            if (!$ok) throw new RuntimeException('No se pudieron cambiar los permisos.');
            if (is_ajax()) json_response(['success' => true, 'message' => 'Permisos actualizados a ' . $modeRaw]);
            flash('ok', 'Permisos actualizados.');
        } elseif ($action === 'delete') {
            $src = full_path((string)$_POST['src'], $baseDir);
            assert_inside($src, $baseDir);
            if ($src === $baseDir) throw new RuntimeException('No se puede borrar el directorio raíz.');
            if (!rrmdir($src)) throw new RuntimeException('No se pudo eliminar el elemento.');
            if (is_ajax()) json_response(['success' => true, 'message' => 'Elemento eliminado.']);
            flash('ok', 'Elemento eliminado.');
        } elseif ($action === 'copy' || $action === 'move') {
            $src = full_path((string)$_POST['src'], $baseDir);
            $destRel = normalize_rel((string)($_POST['dest'] ?? ''));
            $dstDir = $destRel === '' ? $cwd : full_path($destRel, $baseDir);
            assert_inside($src, $baseDir);
            assert_inside($dstDir, $baseDir);
            if (!is_dir($dstDir)) throw new RuntimeException('La carpeta de destino no existe.');
            $dst = rtrim($dstDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($src);
            assert_inside($dst, $baseDir);
            $ok = ($action === 'move') ? @rename($src, $dst) : copy_recursive($src, $dst);
            if (!$ok) throw new RuntimeException($action === 'move' ? 'No se pudo mover.' : 'No se pudo copiar.');
            if (is_ajax()) json_response(['success' => true, 'message' => ($action === 'move' ? 'Movido' : 'Copiado') . ' correctamente.']);
            flash('ok', $action === 'move' ? 'Elemento movido.' : 'Elemento copiado.');
        } elseif (str_starts_with($action, 'bulk_')) {
            $selected = array_values(array_filter(array_map('normalize_rel', (array)($_POST['selected'] ?? []))));
            if (!$selected) throw new RuntimeException('Selecciona al menos un archivo o carpeta.');
            $sources = [];
            foreach ($selected as $rel) {
                $f = full_path($rel, $baseDir);
                assert_inside($f, $baseDir);
                if (file_exists($f)) $sources[] = $f;
            }
            if (!$sources) throw new RuntimeException('No se encontraron los elementos seleccionados.');

            if ($action === 'bulk_delete') {
                $delCount = 0;
                foreach ($sources as $src) {
                    if ($src !== $baseDir && rrmdir($src)) $delCount++;
                }
                if (is_ajax()) json_response(['success' => true, 'message' => "$delCount elemento(s) eliminados."]);
                flash('ok', "$delCount elemento(s) eliminados.");
            } elseif ($action === 'bulk_copy' || $action === 'bulk_move') {
                $destRel = normalize_rel((string)($_POST['bulk_dest'] ?? ''));
                $dstDir = $destRel === '' ? $cwd : full_path($destRel, $baseDir);
                assert_inside($dstDir, $baseDir);
                if (!is_dir($dstDir)) throw new RuntimeException('La carpeta de destino no existe.');
                foreach ($sources as $src) {
                    $dst = rtrim($dstDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($src);
                    assert_inside($dst, $baseDir);
                    $ok = $action === 'bulk_move' ? @rename($src, $dst) : copy_recursive($src, $dst);
                    if (!$ok) throw new RuntimeException('Error al procesar: ' . basename($src));
                }
                flash('ok', $action === 'bulk_move' ? 'Selección movida.' : 'Selección copiada.');
            } elseif ($action === 'bulk_chmod') {
                $modeRaw = preg_replace('/[^0-7]/', '', (string)($_POST['bulk_mode'] ?? '0644')) ?: '0644';
                $mode = (int)base_convert($modeRaw, 8, 10);
                $recursive = !empty($_POST['bulk_recursive']);
                foreach ($sources as $src) {
                    if ($recursive) chmod_recursive($src, $mode);
                    else @chmod($src, $mode);
                }
                flash('ok', "Permisos $modeRaw aplicados a la selección.");
            } elseif ($action === 'bulk_zip' || $action === 'bulk_download') {
                $zip = command_path('zip');
                if (!$zip) throw new RuntimeException('La utilidad zip no está disponible en el servidor.');
                $zipName = basename(trim((string)($_POST['bulk_zip_name'] ?? 'seleccion.zip')) ?: 'seleccion.zip');
                if (!str_ends_with(strtolower($zipName), '.zip')) $zipName .= '.zip';
                $zipFile = $action === 'bulk_download'
                    ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fm-bulk-' . bin2hex(random_bytes(8)) . '.zip'
                    : $cwd . DIRECTORY_SEPARATOR . $zipName;
                assert_inside($action === 'bulk_download' ? $cwd : $zipFile, $baseDir);

                $args = [];
                foreach ($sources as $src) {
                    if (dirname($src) !== $cwd) throw new RuntimeException('La selección debe pertenecer a la carpeta actual.');
                    $args[] = escapeshellarg(basename($src));
                }
                $cmd = 'cd ' . escapeshellarg($cwd) . ' && ' . escapeshellarg($zip) . ' -r -q ' . escapeshellarg($zipFile) . ' ' . implode(' ', $args);
                $output = '';
                if (!run_command($cmd, $output)) throw new RuntimeException('Error al crear ZIP: ' . $output);

                if ($action === 'bulk_download') {
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . $zipName . '"');
                    header('Content-Length: ' . filesize($zipFile));
                    readfile($zipFile);
                    @unlink($zipFile);
                    exit;
                }
                flash('ok', 'Archivo ZIP creado: ' . $zipName);
            } elseif ($action === 'bulk_unzip') {
                $unzip = command_path('unzip');
                if (!$unzip) throw new RuntimeException('La utilidad unzip no está disponible.');
                $destRel = normalize_rel((string)($_POST['bulk_unzip_dest'] ?? ''));
                $destDir = $destRel === '' ? $cwd : full_path($destRel, $baseDir);
                assert_inside($destDir, $baseDir);
                if (!is_dir($destDir) && !@mkdir($destDir, 0775, true)) throw new RuntimeException('No se pudo crear la carpeta destino.');
                foreach ($sources as $src) {
                    if (!is_file($src) || strtolower(pathinfo($src, PATHINFO_EXTENSION)) !== 'zip') continue;
                    assert_zip_entries_inside($src, $destDir, $baseDir);
                    $cmd = escapeshellarg($unzip) . ' -o -q ' . escapeshellarg($src) . ' -d ' . escapeshellarg($destDir);
                    $output = '';
                    if (!run_command($cmd, $output)) throw new RuntimeException('Error al extraer ' . basename($src) . ': ' . $output);
                }
                flash('ok', 'Archivos ZIP extraídos.');
            }
        } elseif ($action === 'zip') {
            $zip = command_path('zip');
            if (!$zip) throw new RuntimeException('La utilidad zip no está instalada.');
            $src = full_path((string)$_POST['src'], $baseDir);
            assert_inside($src, $baseDir);
            if (!file_exists($src)) throw new RuntimeException('El origen no existe.');
            $defaultName = basename($src) . '.zip';
            $zipName = basename(trim((string)($_POST['zip_name'] ?? '')) ?: $defaultName);
            if (!str_ends_with(strtolower($zipName), '.zip')) $zipName .= '.zip';
            $zipFile = $cwd . DIRECTORY_SEPARATOR . $zipName;
            assert_inside($zipFile, $baseDir);
            $cmd = 'cd ' . escapeshellarg(dirname($src)) . ' && ' . escapeshellarg($zip) . ' -r -q ' . escapeshellarg($zipFile) . ' ' . escapeshellarg(basename($src));
            $output = '';
            if (!run_command($cmd, $output)) throw new RuntimeException('Error al comprimir: ' . $output);
            if (is_ajax()) json_response(['success' => true, 'message' => "Comprimido como $zipName"]);
            flash('ok', 'ZIP creado: ' . $zipName);
        } elseif ($action === 'unzip') {
            $unzip = command_path('unzip');
            if (!$unzip) throw new RuntimeException('La utilidad unzip no está instalada.');
            $src = full_path((string)$_POST['src'], $baseDir);
            assert_inside($src, $baseDir);
            if (!is_file($src) || strtolower(pathinfo($src, PATHINFO_EXTENSION)) !== 'zip') {
                throw new RuntimeException('Selecciona un archivo .zip válido.');
            }
            $destRel = normalize_rel((string)($_POST['dest'] ?? ''));
            $destDir = $destRel === '' ? $cwd : full_path($destRel, $baseDir);
            assert_inside($destDir, $baseDir);
            if (!is_dir($destDir) && !@mkdir($destDir, 0775, true)) throw new RuntimeException('No se pudo crear la carpeta destino.');
            assert_zip_entries_inside($src, $destDir, $baseDir);
            $cmd = escapeshellarg($unzip) . ' -o -q ' . escapeshellarg($src) . ' -d ' . escapeshellarg($destDir);
            $output = '';
            if (!run_command($cmd, $output)) throw new RuntimeException('Error al extraer: ' . $output);
            if (is_ajax()) json_response(['success' => true, 'message' => 'ZIP extraído correctamente.']);
            flash('ok', 'ZIP extraído con éxito.');
        }
    } catch (Throwable $e) {
        if (is_ajax()) {
            json_response(['success' => false, 'error' => $e->getMessage()], 400);
        }
        flash('bad', $e->getMessage());
    }
    redirect_here($cwdRel);
}

$items = [];
$totalBytes = 0;
$folderCount = 0;
$fileCount = 0;

foreach (scandir($cwd) ?: [] as $name) {
    if ($name === '.' || $name === '..') {
        continue;
    }
    $path = $cwd . DIRECTORY_SEPARATOR . $name;
    $isDir = is_dir($path);
    $size = $isDir ? 0 : (@filesize($path) ?: 0);
    $totalBytes += $size;
    if ($isDir) $folderCount++; else $fileCount++;

    $category = file_category($path);
    $items[] = [
        'name' => $name,
        'path' => $path,
        'rel' => rel_from_full($path, $baseDir),
        'dir' => $isDir,
        'category' => $category,
        'size' => $size,
        'size_h' => $isDir ? '-' : bytes_h((float)$size),
        'mtime' => @filemtime($path) ?: 0,
        'mtime_h' => date('Y-m-d H:i', @filemtime($path) ?: time()),
        'perms' => perms_h($path),
        'octal' => octal_perms($path),
        'w' => is_writable($path),
        'r' => is_readable($path),
        'ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
    ];
}

usort($items, static fn($a, $b) => ($a['dir'] === $b['dir']) ? strnatcasecmp($a['name'], $b['name']) : ($a['dir'] ? -1 : 1));

$crumbs = [['', 'Raíz']];
$acc = '';
foreach (explode('/', $cwdRel) as $part) {
    if ($part === '') continue;
    $acc = trim($acc . '/' . $part, '/');
    $crumbs[] = [$acc, $part];
}

$freeSpace = @disk_free_space($cwd) ?: 0;
$totalSpace = @disk_total_space($cwd) ?: 0;
$usedSpace = max(0, $totalSpace - $freeSpace);
$usedPct = $totalSpace > 0 ? round(($usedSpace / $totalSpace) * 100) : 0;
?>
<!doctype html>
<html lang="es" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo h(APP_NAME); ?><?php echo $cwdRel ? ' — /' . h($cwdRel) : ''; ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/material-darker.min.css">
<style>
:root[data-theme="dark"] {
    --bg: #090d14;
    --surface: #111724;
    --surface2: #172033;
    --surface3: #1e293f;
    --border: #222e44;
    --border-light: #2c3a54;
    --text: #f1f5f9;
    --subtext: #94a3b8;
    --muted: #64748b;
    --primary: #3b82f6;
    --primary-hover: #2563eb;
    --primary-soft: rgba(59,130,246,0.15);
    --success: #10b981;
    --success-soft: rgba(16,185,129,0.15);
    --danger: #ef4444;
    --danger-hover: #dc2626;
    --danger-soft: rgba(239,68,68,0.15);
    --warning: #f59e0b;
    --warning-soft: rgba(245,158,11,0.15);
    --card-shadow: 0 10px 30px -10px rgba(0,0,0,0.5);
    --modal-backdrop: rgba(4,8,16,0.75);
}
:root[data-theme="light"] {
    --bg: #f8fafc;
    --surface: #ffffff;
    --surface2: #f1f5f9;
    --surface3: #e2e8f0;
    --border: #e2e8f0;
    --border-light: #cbd5e1;
    --text: #0f172a;
    --subtext: #475569;
    --muted: #94a3b8;
    --primary: #2563eb;
    --primary-hover: #1d4ed8;
    --primary-soft: rgba(37,99,235,0.1);
    --success: #059669;
    --success-soft: rgba(5,150,105,0.1);
    --danger: #dc2626;
    --danger-hover: #b91c1c;
    --danger-soft: rgba(220,38,38,0.1);
    --warning: #d97706;
    --warning-soft: rgba(217,119,6,0.1);
    --card-shadow: 0 10px 30px -10px rgba(0,0,0,0.08);
    --modal-backdrop: rgba(15,23,42,0.4);
}

*{box-sizing:border-box;margin:0;padding:0}
body{font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text);font-size:13.5px;line-height:1.5;min-height:100vh;display:flex;flex-direction:column}
a{color:inherit;text-decoration:none}
button,input,select,textarea{font:inherit;color:inherit}
button{cursor:pointer;border:none;background:none;outline:none}
input,select,textarea{outline:none;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text)}
input:focus,select:focus,textarea:focus{border-color:var(--primary);box-shadow:0 0 0 2px var(--primary-soft)}

.app-header{background:var(--surface);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:30;box-shadow:0 1px 3px rgba(0,0,0,0.05)}
.top-nav{display:flex;align-items:center;justify-content:space-between;padding:10px 24px;gap:16px;height:62px}
.brand-group{display:flex;align-items:center;gap:12px;flex-shrink:0}
.brand-icon{width:36px;height:36px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);border-radius:8px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 10px rgba(59,130,246,0.3);color:#fff}
.brand-title{font-size:16px;font-weight:700;letter-spacing:-0.01em;display:flex;align-items:center;gap:8px}
.brand-badge{font-size:10px;font-weight:700;text-transform:uppercase;background:var(--primary-soft);color:var(--primary);padding:2px 6px;border-radius:4px;border:1px solid rgba(59,130,246,0.3)}

.search-box{flex:1;max-width:380px;position:relative}
.search-box svg{position:absolute;left:11px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--muted);pointer-events:none}
.search-input{width:100%;padding:8px 12px 8px 34px;font-size:13px;border-radius:20px;background:var(--surface2);border:1px solid var(--border);transition:all .2s ease}
.search-input:focus{background:var(--surface);border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-soft)}

.nav-actions{display:flex;align-items:center;gap:8px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:500;transition:all .15s ease;background:var(--surface2);border:1px solid var(--border);color:var(--text);white-space:nowrap}
.btn:hover{background:var(--surface3);border-color:var(--border-light)}
.btn.primary{background:var(--primary);border-color:var(--primary);color:#fff}
.btn.primary:hover{background:var(--primary-hover);box-shadow:0 4px 12px rgba(59,130,246,0.3)}
.btn.danger{background:var(--danger-soft);border-color:rgba(239,68,68,0.3);color:var(--danger)}
.btn.danger:hover{background:var(--danger);color:#fff}
.btn.icon-only{padding:7px;border-radius:8px;min-width:34px;height:34px}
.btn svg{width:16px;height:16px}

.sub-nav{display:flex;align-items:center;justify-content:space-between;padding:8px 24px;border-top:1px solid var(--border);background:var(--surface2);font-size:12.5px;gap:12px;overflow-x:auto}
.breadcrumbs{display:flex;align-items:center;gap:6px;flex-wrap:nowrap;white-space:nowrap}
.crumb-item{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:6px;color:var(--subtext);font-weight:500}
.crumb-item:hover{background:var(--surface3);color:var(--text)}
.crumb-item.active{color:var(--text);font-weight:600;background:var(--surface)}
.crumb-sep{color:var(--muted);font-size:11px}
.path-copy-btn{color:var(--muted);cursor:pointer;padding:3px;border-radius:4px}
.path-copy-btn:hover{color:var(--text);background:var(--surface3)}

.storage-widget{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--subtext);flex-shrink:0}
.storage-bar-track{width:90px;height:6px;background:var(--surface3);border-radius:3px;overflow:hidden}
.storage-bar-fill{height:100%;background:linear-gradient(90deg,#3b82f6,#10b981);border-radius:3px;transition:width .4s ease}

.main-content{flex:1;padding:20px 24px;max-width:1600px;margin:0 auto;width:100%}

.bulk-toolbar{position:sticky;top:62px;z-index:25;background:var(--surface);border:1px solid var(--primary);border-radius:10px;padding:8px 16px;display:none;align-items:center;justify-content:space-between;gap:14px;box-shadow:0 8px 24px rgba(0,0,0,0.15);margin-bottom:16px;animation:slideDown .2s ease}
.bulk-toolbar.show{display:flex}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.bulk-info{display:flex;align-items:center;gap:12px;font-weight:600;color:var(--primary)}
.bulk-btn-group{display:flex;align-items:center;gap:8px}

.toast-container{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast{padding:12px 18px;border-radius:8px;font-size:13px;font-weight:500;box-shadow:0 8px 24px rgba(0,0,0,0.25);display:flex;align-items:center;gap:10px;pointer-events:auto;animation:toastIn .25s ease;max-width:400px}
.toast.ok{background:#065f46;color:#ecfdf5;border:1px solid #10b981}
.toast.bad{background:#7f1d1d;color:#fef2f2;border:1px solid #ef4444}
.toast.info{background:#1e3a8a;color:#eff6ff;border:1px solid #3b82f6}
@keyframes toastIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

.file-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:var(--card-shadow);overflow:hidden}
.table-container{overflow-x:auto}
.file-table{width:100%;border-collapse:collapse;text-align:left;font-size:13px}
.file-table th{background:var(--surface2);padding:11px 16px;font-weight:600;color:var(--subtext);border-bottom:1px solid var(--border);white-space:nowrap;user-select:none}
.file-table th.sortable{cursor:pointer}
.file-table th.sortable:hover{color:var(--text)}
.file-table td{padding:10px 16px;border-bottom:1px solid var(--border);vertical-align:middle;transition:background .1s ease}
.file-table tr:hover td{background:var(--surface2)}
.file-table tr.selected td{background:var(--primary-soft)}
.file-table .col-check{width:40px;text-align:center}
.file-table .col-name{max-width:480px;min-width:220px}
.file-table .col-size{width:120px;color:var(--subtext);white-space:nowrap}
.file-table .col-date{width:160px;color:var(--subtext);white-space:nowrap}
.file-table .col-perms{width:90px;font-family:monospace;font-size:12px;color:var(--muted)}
.file-table .col-actions{width:180px;text-align:right;white-space:nowrap}

.file-link{display:flex;align-items:center;gap:12px;color:var(--text);font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.file-link:hover{color:var(--primary)}
.file-icon{width:22px;height:22px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.file-icon svg{width:100%;height:100%}
.folder-icon{color:#eab308}
.code-icon{color:#60a5fa}
.image-icon{color:#a78bfa}
.video-icon{color:#f472b6}
.audio-icon{color:#34d399}
.archive-icon{color:#fb923c}
.pdf-icon{color:#f87171}
.doc-icon{color:#38bdf8}

.action-buttons{display:inline-flex;align-items:center;gap:4px;opacity:0.85;transition:opacity .15s}
.file-table tr:hover .action-buttons{opacity:1}
.act-btn{padding:5px;border-radius:6px;color:var(--subtext);display:flex;align-items:center;justify-content:center}
.act-btn:hover{background:var(--surface3);color:var(--text)}
.act-btn.danger:hover{background:var(--danger-soft);color:var(--danger)}
.act-btn svg{width:15px;height:15px}

.grid-container{display:none;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;padding:20px}
.view-grid .table-container{display:none}
.view-grid .grid-container{display:grid}
.grid-item{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px;display:flex;flex-direction:column;align-items:center;text-align:center;position:relative;transition:all .15s ease;cursor:pointer}
.grid-item:hover{border-color:var(--primary);transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,0.1)}
.grid-item.selected{border-color:var(--primary);background:var(--primary-soft)}
.grid-check{position:absolute;top:10px;left:10px;display:none}
.grid-item:hover .grid-check,.grid-item.selected .grid-check{display:block}
.grid-icon{width:48px;height:48px;margin:12px 0 10px;display:flex;align-items:center;justify-content:center}
.grid-icon svg{width:100%;height:100%}
.grid-thumb{width:100%;height:90px;object-fit:cover;border-radius:6px;margin-bottom:8px}
.grid-name{font-size:12.5px;font-weight:500;width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-bottom:4px}
.grid-meta{font-size:11px;color:var(--muted)}
.grid-menu-btn{position:absolute;top:8px;right:8px;padding:4px;border-radius:4px;color:var(--subtext);opacity:0}
.grid-item:hover .grid-menu-btn{opacity:1}

.empty-state{text-align:center;padding:60px 20px;color:var(--subtext)}
.empty-icon{width:48px;height:48px;margin:0 auto 12px;color:var(--muted)}

.modal-backdrop{position:fixed;inset:0;background:var(--modal-backdrop);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;padding:20px;z-index:900}
.modal-backdrop.show{display:flex}
.modal-card{width:100%;max-width:500px;background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:var(--card-shadow);overflow:hidden;animation:modalIn .2s ease}
@keyframes modalIn{from{opacity:0;transform:scale(0.96)}to{opacity:1;transform:scale(1)}}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border)}
.modal-header h3{font-size:16px;font-weight:600}
.modal-body{padding:20px}
.modal-footer{padding:14px 20px;border-top:1px solid var(--border);background:var(--surface2);display:flex;justify-content:flex-end;gap:10px}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:12.5px;font-weight:500;margin-bottom:6px;color:var(--subtext)}
.form-group input,.form-group select{width:100%}

.editor-card{max-width:1400px;width:96vw;height:92vh;display:flex;flex-direction:column}
.editor-header{display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-bottom:1px solid var(--border);background:var(--surface)}
.editor-body{flex:1;position:relative;overflow:hidden;background:#15181e}
.editor-footer{display:flex;align-items:center;justify-content:space-between;padding:8px 20px;border-top:1px solid var(--border);background:var(--surface);font-size:12px;color:var(--subtext)}
.CodeMirror{height:100% !important;font-family:ui-monospace,SFMono-Regular,Consolas,"Liberation Mono",Menlo,monospace;font-size:13.5px;line-height:1.6}
.editor-native{width:100%;height:100%;resize:none;background:transparent;border:none;color:#f1f5f9;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:13px;padding:14px;line-height:1.6}

.preview-card{max-width:1100px;width:95vw;max-height:92vh;display:flex;flex-direction:column;background:var(--surface)}
.preview-body{padding:0;display:flex;align-items:center;justify-content:center;background:#06080d;min-height:360px;max-height:75vh;overflow:auto}
.preview-media{max-width:100%;max-height:75vh;object-fit:contain}
.preview-pdf{width:100%;height:75vh;border:none}
.preview-audio-wrap{padding:40px;width:100%;max-width:550px;text-align:center}
.preview-audio-wrap audio{width:100%;margin-top:16px}

.chmod-matrix{display:grid;grid-template-columns:100px repeat(3,1fr);gap:8px;align-items:center;margin-bottom:14px;background:var(--surface2);padding:14px;border-radius:8px;border:1px solid var(--border)}
.chmod-matrix span{font-weight:600;font-size:12.5px}
.chmod-presets{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
.chmod-tag{font-size:11.5px;padding:4px 8px;background:var(--surface2);border:1px solid var(--border);border-radius:6px;cursor:pointer}
.chmod-tag:hover{background:var(--surface3);border-color:var(--primary)}

.dropzone-overlay{position:fixed;inset:0;background:rgba(59,130,246,0.25);backdrop-filter:blur(6px);border:4px dashed var(--primary);display:none;align-items:center;justify-content:center;z-index:999;pointer-events:none}
.dropzone-overlay.active{display:flex}
.dropzone-box{background:var(--surface);padding:30px 50px;border-radius:16px;text-align:center;box-shadow:var(--card-shadow)}

.progress-wrap{width:100%;height:10px;background:var(--surface3);border-radius:5px;overflow:hidden;margin:14px 0 6px}
.progress-fill{height:100%;background:linear-gradient(90deg,var(--primary),#10b981);width:0%;transition:width .15s ease}

.terminal-modal{max-width:1440px;width:98vw;height:94vh;display:flex;flex-direction:column}
.terminal-frame{width:100%;flex:1;border:none;background:#000}

.dropdown{position:relative}
.drop-menu{position:absolute;top:100%;right:0;margin-top:6px;background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:var(--card-shadow);min-width:160px;padding:6px;display:none;flex-direction:column;gap:2px;z-index:50}
.drop-menu.show{display:flex}
.drop-item{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:6px;font-size:12.5px;color:var(--text);transition:background .1s}
.drop-item:hover{background:var(--surface2);color:var(--primary)}

@media(max-width:768px){
    .top-nav{padding:10px 14px}
    .sub-nav{padding:8px 14px}
    .main-content{padding:14px}
    .search-box{max-width:180px}
    .hide-mobile{display:none !important}
    .file-table .col-size,.file-table .col-date,.file-table .col-perms{display:none}
}
</style>
</head>
<body>

<div class="dropzone-overlay" id="dropOverlay">
    <div class="dropzone-box">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--primary);margin-bottom:12px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <h2 style="font-size:18px;margin-bottom:4px">Suelta los archivos aquí</h2>
        <p style="color:var(--subtext);font-size:13px">Se subirán directamente a <strong>/<?php echo h($cwdRel); ?></strong></p>
    </div>
</div>

<div class="toast-container" id="toastContainer">
    <?php foreach ($_SESSION['flash'] ?? [] as $msg): ?>
        <div class="toast <?php echo h($msg[0]); ?>">
            <span><?php echo h($msg[1]); ?></span>
        </div>
    <?php endforeach; $_SESSION['flash'] = []; ?>
</div>

<header class="app-header">
    <div class="top-nav">
        <div class="brand-group">
            <div class="brand-icon">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>
            </div>
            <div class="brand-title">
                <?php echo h(APP_NAME); ?>
                <span class="brand-badge">Pro</span>
            </div>
        </div>

        <div class="search-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" class="search-input" id="filterInput" placeholder="Buscar en esta carpeta... (Ctrl + F)">
        </div>

        <div class="nav-actions">
            <div class="dropdown">
                <button class="btn primary" id="btnNewDropdown">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span>Nuevo</span>
                </button>
                <div class="drop-menu" id="newMenu">
                    <button class="drop-item" type="button" data-modal="create-folder">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                        <span>Nueva Carpeta</span>
                    </button>
                    <button class="drop-item" type="button" data-modal="create-file">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <span>Nuevo Archivo</span>
                    </button>
                </div>
            </div>

            <button class="btn" id="btnUploadTrigger">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <span>Subir</span>
            </button>
            <input type="file" id="globalFileInput" multiple style="display:none">

            <button class="btn hide-mobile" data-modal="terminal" title="Consola Web">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
                <span>Terminal</span>
            </button>

            <button class="btn icon-only" id="themeToggle" title="Cambiar Tema (Oscuro/Claro)">
                <svg id="themeIconDark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
            </button>

            <button class="btn icon-only" id="viewModeToggle" title="Alternar Vista (Lista / Cuadrícula)">
                <svg id="viewGridIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            </button>

            <a class="btn icon-only danger" href="?logout=1" title="Cerrar Sesión">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </a>
        </div>
    </div>

    <div class="sub-nav">
        <div class="breadcrumbs">
            <?php foreach ($crumbs as $i => [$rel, $name]): ?>
                <?php if ($i > 0): ?><span class="crumb-sep">/</span><?php endif; ?>
                <a class="crumb-item <?php echo ($i === count($crumbs) - 1) ? 'active' : ''; ?>" href="?p=<?php echo rawurlencode($rel); ?>">
                    <?php if ($i === 0): ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                    <?php endif; ?>
                    <span><?php echo h($name); ?></span>
                </a>
            <?php endforeach; ?>
            <button class="path-copy-btn" id="copyPathBtn" title="Copiar ruta absoluta" data-path="<?php echo h($cwd); ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            </button>
        </div>

        <div class="storage-widget">
            <span><?php echo $folderCount; ?> carpetas, <?php echo $fileCount; ?> archivos (<?php echo bytes_h((float)$totalBytes); ?>)</span>
            <span class="crumb-sep">•</span>
            <span>Libre: <?php echo bytes_h((float)$freeSpace); ?> de <?php echo bytes_h((float)$totalSpace); ?></span>
            <div class="storage-bar-track" title="Almacenamiento usado: <?php echo $usedPct; ?>%">
                <div class="storage-bar-fill" style="width:<?php echo $usedPct; ?>%"></div>
            </div>
        </div>
    </div>
</header>

<main class="main-content">
    <form method="post" id="bulkForm">
        <input type="hidden" name="csrf" value="<?php echo h(token()); ?>">
        <input type="hidden" name="p" value="<?php echo h($cwdRel); ?>">

        <div class="bulk-toolbar" id="bulkToolbar">
            <div class="bulk-info">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                <span id="selectedCountText">0 seleccionados</span>
            </div>
            <div class="bulk-btn-group">
                <button class="btn" type="submit" name="action" value="bulk_download" title="Descargar como ZIP">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <span>Descargar ZIP</span>
                </button>
                <button class="btn" type="button" data-modal="bulk-move">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="5 9 2 12 5 15"/><polyline points="9 5 12 2 15 5"/><polyline points="15 19 12 22 9 19"/><polyline points="19 9 22 12 19 15"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="22"/></svg>
                    <span>Mover</span>
                </button>
                <button class="btn" type="button" data-modal="bulk-copy">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    <span>Copiar</span>
                </button>
                <button class="btn" type="button" data-modal="bulk-chmod">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <span>Permisos</span>
                </button>
                <button class="btn danger" type="submit" name="action" value="bulk_delete" id="btnBulkDelete">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    <span>Eliminar</span>
                </button>
                <button class="btn icon-only" type="button" id="clearSelectBtn" title="Deseleccionar todo">✕</button>
            </div>
        </div>

        <div class="file-card" id="fileCardContainer">
            <div class="table-container">
                <table class="file-table" id="fileTable">
                    <thead>
                        <tr>
                            <th class="col-check"><input type="checkbox" id="selectAll"></th>
                            <th class="col-name sortable" data-sort="name">Nombre ↕</th>
                            <th class="col-size sortable" data-sort="size">Tamaño ↕</th>
                            <th class="col-date sortable" data-sort="mtime">Modificado ↕</th>
                            <th class="col-perms">Permisos</th>
                            <th class="col-actions">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($cwdRel !== ''): ?>
                        <tr class="up-level-row">
                            <td class="col-check"></td>
                            <td colspan="5">
                                <a class="file-link" href="?p=<?php echo rawurlencode(dirname($cwdRel) === '.' ? '' : dirname($cwdRel)); ?>">
                                    <div class="file-icon folder-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                                    </div>
                                    <strong>.. (Subir al directorio superior)</strong>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php if (!$items): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <div class="empty-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>
                                    </div>
                                    <p>Esta carpeta está vacía.</p>
                                    <p style="font-size:12px;color:var(--muted);margin-top:4px">Arrastra archivos aquí o usa el botón "+ Nuevo"</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php foreach ($items as $item): ?>
                        <tr class="file-row" data-name="<?php echo h(strtolower($item['name'])); ?>" data-size="<?php echo $item['size']; ?>" data-mtime="<?php echo $item['mtime']; ?>" data-dir="<?php echo $item['dir'] ? '1' : '0'; ?>">
                            <td class="col-check">
                                <input type="checkbox" name="selected[]" value="<?php echo h($item['rel']); ?>" class="row-checkbox">
                            </td>
                            <td class="col-name">
                                <?php if ($item['dir']): ?>
                                    <a class="file-link" href="?p=<?php echo rawurlencode($item['rel']); ?>" title="<?php echo h($item['name']); ?>">
                                        <div class="file-icon folder-icon">
                                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.5 21a3 3 0 0 0 3-3v-4.5a3 3 0 0 0-3-3h-1.5V9a3 3 0 0 0-3-3h-3.414a2 2 0 0 1-1.414-.586L9.172 4.414A2 2 0 0 0 7.758 4H4.5A3 3 0 0 0 1.5 7v11a3 3 0 0 0 3 3h15z"/></svg>
                                        </div>
                                        <span><?php echo h($item['name']); ?></span>
                                    </a>
                                <?php elseif ($item['category'] === 'image' || $item['category'] === 'video' || $item['category'] === 'audio' || $item['category'] === 'pdf'): ?>
                                    <a class="file-link preview-trigger" href="?raw=<?php echo rawurlencode($item['rel']); ?>" data-category="<?php echo $item['category']; ?>" data-name="<?php echo h($item['name']); ?>" data-rel="<?php echo h($item['rel']); ?>" title="Click para vista previa">
                                        <div class="file-icon <?php echo $item['category']; ?>-icon">
                                            <?php if ($item['category'] === 'image'): ?>
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                            <?php elseif ($item['category'] === 'video'): ?>
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                                            <?php elseif ($item['category'] === 'audio'): ?>
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
                                            <?php else: ?>
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                            <?php endif; ?>
                                        </div>
                                        <span><?php echo h($item['name']); ?></span>
                                    </a>
                                <?php elseif ($item['category'] === 'code' || $item['category'] === 'text'): ?>
                                    <a class="file-link edit-trigger" href="#" data-rel="<?php echo h($item['rel']); ?>" data-name="<?php echo h($item['name']); ?>" title="Click para editar código">
                                        <div class="file-icon <?php echo $item['category']; ?>-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                                        </div>
                                        <span><?php echo h($item['name']); ?></span>
                                    </a>
                                <?php else: ?>
                                    <a class="file-link" href="?download=<?php echo rawurlencode($item['rel']); ?>" title="Click para descargar">
                                        <div class="file-icon archive-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                                        </div>
                                        <span><?php echo h($item['name']); ?></span>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td class="col-size"><?php echo $item['size_h']; ?></td>
                            <td class="col-date"><?php echo $item['mtime_h']; ?></td>
                            <td class="col-perms"><span title="Octal: <?php echo $item['octal']; ?>"><?php echo $item['perms']; ?></span></td>
                            <td class="col-actions">
                                <div class="action-buttons">
                                    <?php if ($item['category'] === 'image' || $item['category'] === 'video' || $item['category'] === 'audio' || $item['category'] === 'pdf'): ?>
                                        <button type="button" class="act-btn preview-trigger" data-category="<?php echo $item['category']; ?>" data-name="<?php echo h($item['name']); ?>" data-rel="<?php echo h($item['rel']); ?>" title="Vista previa">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                    <?php endif; ?>

                                    <?php if (!$item['dir'] && ($item['category'] === 'code' || $item['category'] === 'text')): ?>
                                        <button type="button" class="act-btn edit-trigger" data-rel="<?php echo h($item['rel']); ?>" data-name="<?php echo h($item['name']); ?>" title="Editar">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </button>
                                    <?php endif; ?>

                                    <?php if (!$item['dir']): ?>
                                        <a class="act-btn" href="?download=<?php echo rawurlencode($item['rel']); ?>" title="Descargar">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        </a>
                                    <?php else: ?>
                                        <a class="act-btn" href="?zip_folder=<?php echo rawurlencode($item['rel']); ?>" title="Descargar carpeta como ZIP">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        </a>
                                    <?php endif; ?>

                                    <button type="button" class="act-btn" data-modal="rename" data-rel="<?php echo h($item['rel']); ?>" data-name="<?php echo h($item['name']); ?>" title="Renombrar">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                                    </button>

                                    <button type="button" class="act-btn" data-modal="duplicate" data-rel="<?php echo h($item['rel']); ?>" data-name="<?php echo h($item['name']); ?>" title="Duplicar">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                    </button>

                                    <button type="button" class="act-btn" data-modal="chmod" data-rel="<?php echo h($item['rel']); ?>" data-octal="<?php echo $item['octal']; ?>" data-name="<?php echo h($item['name']); ?>" title="Permisos (chmod)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    </button>

                                    <?php if ($item['ext'] === 'zip'): ?>
                                        <button type="button" class="act-btn" data-modal="unzip" data-rel="<?php echo h($item['rel']); ?>" title="Extraer ZIP">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="act-btn" data-modal="zip" data-rel="<?php echo h($item['rel']); ?>" data-name="<?php echo h($item['name']); ?>" title="Comprimir a ZIP">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                                        </button>
                                    <?php endif; ?>

                                    <button type="button" class="act-btn danger" data-modal="delete" data-rel="<?php echo h($item['rel']); ?>" data-name="<?php echo h($item['name']); ?>" title="Eliminar">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="grid-container" id="gridContainer">
                <?php foreach ($items as $item): ?>
                <div class="grid-item" data-rel="<?php echo h($item['rel']); ?>" data-name="<?php echo h(strtolower($item['name'])); ?>">
                    <input type="checkbox" name="selected_grid[]" value="<?php echo h($item['rel']); ?>" class="grid-check">
                    <?php if ($item['category'] === 'image'): ?>
                        <img src="?raw=<?php echo rawurlencode($item['rel']); ?>" class="grid-thumb preview-trigger" data-category="image" data-name="<?php echo h($item['name']); ?>" data-rel="<?php echo h($item['rel']); ?>" loading="lazy" alt="<?php echo h($item['name']); ?>">
                    <?php else: ?>
                        <div class="grid-icon <?php echo $item['category']; ?>-icon">
                            <?php if ($item['dir']): ?>
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.5 21a3 3 0 0 0 3-3v-4.5a3 3 0 0 0-3-3h-1.5V9a3 3 0 0 0-3-3h-3.414a2 2 0 0 1-1.414-.586L9.172 4.414A2 2 0 0 0 7.758 4H4.5A3 3 0 0 0 1.5 7v11a3 3 0 0 0 3 3h15z"/></svg>
                            <?php elseif ($item['category'] === 'code' || $item['category'] === 'text'): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                            <?php elseif ($item['category'] === 'video'): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="grid-name" title="<?php echo h($item['name']); ?>">
                        <?php if ($item['dir']): ?>
                            <a href="?p=<?php echo rawurlencode($item['rel']); ?>"><?php echo h($item['name']); ?></a>
                        <?php else: ?>
                            <span><?php echo h($item['name']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="grid-meta"><?php echo $item['size_h']; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </form>
</main>

<div class="modal-backdrop" id="modal-create-folder">
    <div class="modal-card">
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo h(token()); ?>">
            <input type="hidden" name="p" value="<?php echo h($cwdRel); ?>">
            <input type="hidden" name="action" value="mkdir">
            <div class="modal-header">
                <h3>Nueva Carpeta</h3>
                <button type="button" class="act-btn" data-close>✕</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Nombre de la carpeta</label>
                    <input type="text" name="name" placeholder="nueva-carpeta" required autofocus>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close>Cancelar</button>
                <button type="submit" class="btn primary">Crear Carpeta</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="modal-create-file">
    <div class="modal-card">
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo h(token()); ?>">
            <input type="hidden" name="p" value="<?php echo h($cwdRel); ?>">
            <input type="hidden" name="action" value="newfile">
            <div class="modal-header">
                <h3>Nuevo Archivo</h3>
                <button type="button" class="act-btn" data-close>✕</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Nombre del archivo</label>
                    <input type="text" name="name" placeholder="ejemplo.php, index.html, notes.txt" required autofocus>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close>Cancelar</button>
                <button type="submit" class="btn primary">Crear Archivo</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="modal-rename">
    <div class="modal-card">
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo h(token()); ?>">
            <input type="hidden" name="p" value="<?php echo h($cwdRel); ?>">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="src" id="renameSrc">
            <div class="modal-header">
                <h3>Renombrar</h3>
                <button type="button" class="act-btn" data-close>✕</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Nuevo nombre</label>
                    <input type="text" name="name" id="renameName" required autofocus>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close>Cancelar</button>
                <button type="submit" class="btn primary">Renombrar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="modal-duplicate">
    <div class="modal-card">
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo h(token()); ?>">
            <input type="hidden" name="p" value="<?php echo h($cwdRel); ?>">
            <input type="hidden" name="action" value="duplicate">
            <input type="hidden" name="src" id="duplicateSrc">
            <div class="modal-header">
                <h3>Duplicar Elemento</h3>
                <button type="button" class="act-btn" data-close>✕</button>
            </div>
            <div class="modal-body">
                <p>¿Deseas duplicar <strong id="duplicateName"></strong>?</p>
                <p style="font-size:12px;color:var(--muted);margin-top:6px">Se creará una copia con el sufijo "_copia".</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close>Cancelar</button>
                <button type="submit" class="btn primary">Duplicar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="modal-chmod">
    <div class="modal-card">
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo h(token()); ?>">
            <input type="hidden" name="p" value="<?php echo h($cwdRel); ?>">
            <input type="hidden" name="action" value="chmod">
            <input type="hidden" name="src" id="chmodSrc">
            <div class="modal-header">
                <h3>Permisos de Archivo</h3>
                <button type="button" class="act-btn" data-close>✕</button>
            </div>
            <div class="modal-body">
                <div class="chmod-presets">
                    <span class="chmod-tag" data-mode="0644">0644 (Estándar Archivo)</span>
                    <span class="chmod-tag" data-mode="0755">0755 (Estándar Carpeta/Script)</span>
                    <span class="chmod-tag" data-mode="0777">0777 (Total)</span>
                    <span class="chmod-tag" data-mode="0600">0600 (Privado)</span>
                </div>

                <div class="chmod-matrix">
                    <span>Nivel</span><span>Lectura (4)</span><span>Escritura (2)</span><span>Ejecutar (1)</span>

                    <span>Propietario</span>
                    <input type="checkbox" id="u_r" data-val="400">
                    <input type="checkbox" id="u_w" data-val="200">
                    <input type="checkbox" id="u_x" data-val="100">

                    <span>Grupo</span>
                    <input type="checkbox" id="g_r" data-val="040">
                    <input type="checkbox" id="g_w" data-val="020">
                    <input type="checkbox" id="g_x" data-val="010">

                    <span>Otros</span>
                    <input type="checkbox" id="o_r" data-val="004">
                    <input type="checkbox" id="o_w" data-val="002">
                    <input type="checkbox" id="o_x" data-val="001">
                </div>

                <div class="form-group" style="display:flex;align-items:center;gap:12px">
                    <div style="flex:1">
                        <label>Código Octal</label>
                        <input type="text" name="mode" id="chmodModeInput" value="0644" style="font-family:monospace;font-size:15px;font-weight:700">
                    </div>
                </div>

                <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;cursor:pointer">
                    <input type="checkbox" name="recursive" value="1">
                    <span>Aplicar recursivamente a todos los elementos contenidos</span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close>Cancelar</button>
                <button type="submit" class="btn primary">Guardar Permisos</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="modal-zip">
    <div class="modal-card">
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo h(token()); ?>">
            <input type="hidden" name="p" value="<?php echo h($cwdRel); ?>">
            <input type="hidden" name="action" value="zip">
            <input type="hidden" name="src" id="zipSrc">
            <div class="modal-header">
                <h3>Comprimir en archivo ZIP</h3>
                <button type="button" class="act-btn" data-close>✕</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Nombre del archivo .zip</label>
                    <input type="text" name="zip_name" id="zipName" placeholder="archivo.zip" required autofocus>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close>Cancelar</button>
                <button type="submit" class="btn primary">Comprimir</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="modal-unzip">
    <div class="modal-card">
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo h(token()); ?>">
            <input type="hidden" name="p" value="<?php echo h($cwdRel); ?>">
            <input type="hidden" name="action" value="unzip">
            <input type="hidden" name="src" id="unzipSrc">
            <div class="modal-header">
                <h3>Extraer archivo ZIP</h3>
                <button type="button" class="act-btn" data-close>✕</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Carpeta de destino (dejar vacío para carpeta actual)</label>
                    <input type="text" name="dest" placeholder="Ej: extraer-aqui">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close>Cancelar</button>
                <button type="submit" class="btn primary">Extraer Todo</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="modal-delete">
    <div class="modal-card">
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo h(token()); ?>">
            <input type="hidden" name="p" value="<?php echo h($cwdRel); ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="src" id="deleteSrc">
            <div class="modal-header">
                <h3>Confirmar Eliminación</h3>
                <button type="button" class="act-btn" data-close>✕</button>
            </div>
            <div class="modal-body">
                <p>¿Estás completamente seguro de que deseas eliminar <strong id="deleteName"></strong>?</p>
                <p style="color:var(--danger);font-size:12px;margin-top:8px">Esta acción no se puede deshacer y borrará el elemento permanentemente.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close>Cancelar</button>
                <button type="submit" class="btn danger">Sí, Eliminar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="modal-bulk-move">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Mover selección</h3>
            <button type="button" class="act-btn" data-close>✕</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Carpeta de destino (relativa a raíz)</label>
                <input type="text" id="bulkMoveDest" placeholder="Ej: carpeta/subcarpeta (vacío = actual)">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" data-close>Cancelar</button>
            <button type="button" class="btn primary" id="btnConfirmBulkMove">Mover Elementos</button>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="modal-bulk-copy">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Copiar selección</h3>
            <button type="button" class="act-btn" data-close>✕</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Carpeta de destino (relativa a raíz)</label>
                <input type="text" id="bulkCopyDest" placeholder="Ej: backups (vacío = actual)">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" data-close>Cancelar</button>
            <button type="button" class="btn primary" id="btnConfirmBulkCopy">Copiar Elementos</button>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="modal-bulk-chmod">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Cambiar permisos de la selección</h3>
            <button type="button" class="act-btn" data-close>✕</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Modo Octal</label>
                <input type="text" id="bulkChmodMode" value="0644" style="font-family:monospace;font-weight:700">
            </div>
            <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;cursor:pointer">
                <input type="checkbox" id="bulkChmodRecursive" value="1">
                <span>Aplicar recursivamente dentro de carpetas</span>
            </label>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" data-close>Cancelar</button>
            <button type="button" class="btn primary" id="btnConfirmBulkChmod">Aplicar a Selección</button>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="modal-editor">
    <div class="modal-card editor-card">
        <div class="editor-header">
            <div style="display:flex;align-items:center;gap:10px">
                <h3 id="editorFileName">Editor</h3>
                <span id="editorStatusBadge" style="font-size:11px;padding:2px 6px;border-radius:4px;background:var(--surface2);color:var(--subtext)">Listo</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <button type="button" class="btn primary" id="btnEditorSave" title="Guardar cambios (Ctrl + S)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <span>Guardar (Ctrl+S)</span>
                </button>
                <button type="button" class="btn" data-close>Cerrar</button>
            </div>
        </div>
        <div class="editor-body">
            <textarea id="nativeEditor" class="editor-native" spellcheck="false"></textarea>
        </div>
        <div class="editor-footer">
            <div id="editorFileDetails">UTF-8</div>
            <div id="editorCursorPosition">Línea 1, Columna 1</div>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="modal-preview">
    <div class="modal-card preview-card">
        <div class="modal-header">
            <h3 id="previewTitle">Vista Previa</h3>
            <div style="display:flex;align-items:center;gap:8px">
                <a href="#" id="previewDownloadBtn" class="btn" download>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <span>Descargar</span>
                </a>
                <button type="button" class="act-btn" data-close>✕</button>
            </div>
        </div>
        <div class="preview-body" id="previewContainer">
        </div>
    </div>
</div>

<div class="modal-backdrop" id="modal-terminal">
    <div class="modal-card terminal-modal">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:8px">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
                <h3>Consola Web</h3>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <button type="button" class="btn" id="btnRefreshTerminal">Recargar</button>
                <button type="button" class="act-btn" data-close>✕</button>
            </div>
        </div>
        <iframe class="terminal-frame" id="terminalFrame" data-src="<?php echo h($terminalUrl ?: (($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/ttyd/')); ?>"></iframe>
    </div>
</div>

<div class="modal-backdrop" id="modal-upload">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Subiendo archivos...</h3>
        </div>
        <div class="modal-body">
            <p id="uploadStatusText">Subiendo archivos al servidor...</p>
            <div class="progress-wrap">
                <div class="progress-fill" id="uploadProgressBar"></div>
            </div>
            <p style="font-size:12px;color:var(--muted);text-align:right" id="uploadProgressPct">0%</p>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/clike/clike.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/php/php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/python/python.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/markdown/markdown.min.js"></script>

<script>
const CSRF = '<?php echo h(token()); ?>';
const CWD_REL = '<?php echo h($cwdRel); ?>';

const qs = (sel, parent = document) => parent.querySelector(sel);
const qsa = (sel, parent = document) => Array.from(parent.querySelectorAll(sel));

function showToast(message, type = 'info') {
    const container = qs('#toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}

function openModal(id) {
    closeModals();
    const modal = qs('#modal-' + id);
    if (modal) {
        modal.classList.add('show');
        const input = modal.querySelector('input:not([type="hidden"]), textarea');
        if (input) setTimeout(() => input.focus(), 50);
    }
}

function closeModals() {
    qsa('.modal-backdrop').forEach(m => m.classList.remove('show'));
    qsa('.drop-menu').forEach(m => m.classList.remove('show'));
}

document.addEventListener('click', (e) => {
    if (e.target.matches('[data-close]') || e.target.classList.contains('modal-backdrop')) {
        closeModals();
    }
    const trigger = e.target.closest('[data-modal]');
    if (trigger) {
        e.preventDefault();
        const modalId = trigger.dataset.modal;

        if (modalId === 'rename') {
            qs('#renameSrc').value = trigger.dataset.rel;
            qs('#renameName').value = trigger.dataset.name;
        } else if (modalId === 'duplicate') {
            qs('#duplicateSrc').value = trigger.dataset.rel;
            qs('#duplicateName').textContent = trigger.dataset.name;
        } else if (modalId === 'chmod') {
            qs('#chmodSrc').value = trigger.dataset.rel;
            setChmodMatrixFromOctal(trigger.dataset.octal || '0644');
        } else if (modalId === 'zip') {
            qs('#zipSrc').value = trigger.dataset.rel;
            qs('#zipName').value = trigger.dataset.name + '.zip';
        } else if (modalId === 'unzip') {
            qs('#unzipSrc').value = trigger.dataset.rel;
        } else if (modalId === 'delete') {
            qs('#deleteSrc').value = trigger.dataset.rel;
            qs('#deleteName').textContent = trigger.dataset.name;
        } else if (modalId === 'terminal') {
            const frame = qs('#terminalFrame');
            if (frame && !frame.src) {
                frame.src = frame.dataset.src;
            }
        }
        openModal(modalId);
    }
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeModals();
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
        const fi = qs('#filterInput');
        if (fi && document.activeElement !== fi) {
            e.preventDefault();
            fi.focus();
            fi.select();
        }
    }
});

const btnNew = qs('#btnNewDropdown');
const newMenu = qs('#newMenu');
if (btnNew && newMenu) {
    btnNew.addEventListener('click', (e) => {
        e.stopPropagation();
        newMenu.classList.toggle('show');
    });
}
document.addEventListener('click', () => {
    if (newMenu) newMenu.classList.remove('show');
});

const themeToggle = qs('#themeToggle');
const htmlEl = document.documentElement;
const savedTheme = localStorage.getItem('fm_theme') || 'dark';
htmlEl.setAttribute('data-theme', savedTheme);

if (themeToggle) {
    themeToggle.addEventListener('click', () => {
        const cur = htmlEl.getAttribute('data-theme');
        const next = cur === 'dark' ? 'light' : 'dark';
        htmlEl.setAttribute('data-theme', next);
        localStorage.setItem('fm_theme', next);
        if (window.cmEditor) {
            window.cmEditor.setOption('theme', next === 'dark' ? 'material-darker' : 'default');
        }
    });
}

const viewModeToggle = qs('#viewModeToggle');
const fileCardContainer = qs('#fileCardContainer');
const savedView = localStorage.getItem('fm_view') || 'list';
if (savedView === 'grid') fileCardContainer.classList.add('view-grid');

if (viewModeToggle) {
    viewModeToggle.addEventListener('click', () => {
        const isGrid = fileCardContainer.classList.toggle('view-grid');
        localStorage.setItem('fm_view', isGrid ? 'grid' : 'list');
    });
}

const copyPathBtn = qs('#copyPathBtn');
if (copyPathBtn) {
    copyPathBtn.addEventListener('click', () => {
        navigator.clipboard.writeText(copyPathBtn.dataset.path).then(() => {
            showToast('Ruta copiada al portapapeles', 'ok');
        });
    });
}

const filterInput = qs('#filterInput');
if (filterInput) {
    filterInput.addEventListener('input', () => {
        const q = filterInput.value.trim().toLowerCase();
        qsa('.file-row').forEach(row => {
            const name = row.dataset.name || '';
            row.style.display = name.includes(q) ? '' : 'none';
        });
        qsa('.grid-item').forEach(item => {
            const name = item.dataset.name || '';
            item.style.display = name.includes(q) ? '' : 'none';
        });
    });
}

let sortAsc = true;
qsa('.sortable').forEach(th => {
    th.addEventListener('click', () => {
        const field = th.dataset.sort;
        const tbody = qs('#fileTable tbody');
        const rows = qsa('.file-row', tbody);
        sortAsc = !sortAsc;

        rows.sort((a, b) => {
            const aDir = a.dataset.dir === '1';
            const bDir = b.dataset.dir === '1';
            if (aDir !== bDir) return aDir ? -1 : 1;

            if (field === 'size') {
                const sA = parseFloat(a.dataset.size) || 0;
                const sB = parseFloat(b.dataset.size) || 0;
                return sortAsc ? (sA - sB) : (sB - sA);
            }
            if (field === 'mtime') {
                const mA = parseInt(a.dataset.mtime) || 0;
                const mB = parseInt(b.dataset.mtime) || 0;
                return sortAsc ? (mA - mB) : (mB - mA);
            }
            const nA = a.dataset.name;
            const nB = b.dataset.name;
            return sortAsc ? nA.localeCompare(nB) : nB.localeCompare(nA);
        });

        rows.forEach(r => tbody.appendChild(r));
    });
});

const selectAll = qs('#selectAll');
const rowChecks = () => qsa('.row-checkbox');
const bulkToolbar = qs('#bulkToolbar');
const selectedCountText = qs('#selectedCountText');
const clearSelectBtn = qs('#clearSelectBtn');

function updateSelection() {
    const checks = rowChecks();
    const count = checks.filter(c => c.checked).length;
    if (selectedCountText) selectedCountText.textContent = `${count} seleccionado(s)`;
    if (bulkToolbar) {
        if (count > 0) bulkToolbar.classList.add('show');
        else bulkToolbar.classList.remove('show');
    }
    if (selectAll) {
        selectAll.checked = (count > 0 && count === checks.length);
        selectAll.indeterminate = (count > 0 && count < checks.length);
    }
    checks.forEach(c => {
        const tr = c.closest('tr');
        if (tr) tr.classList.toggle('selected', c.checked);
    });
}

if (selectAll) {
    selectAll.addEventListener('change', () => {
        rowChecks().forEach(c => c.checked = selectAll.checked);
        updateSelection();
    });
}

document.addEventListener('change', (e) => {
    if (e.target.classList.contains('row-checkbox')) {
        updateSelection();
    }
});

if (clearSelectBtn) {
    clearSelectBtn.addEventListener('click', () => {
        rowChecks().forEach(c => c.checked = false);
        updateSelection();
    });
}

qs('#btnConfirmBulkMove')?.addEventListener('click', () => {
    const dest = qs('#bulkMoveDest').value;
    const form = qs('#bulkForm');
    const inp = document.createElement('input');
    inp.type = 'hidden';
    inp.name = 'bulk_dest';
    inp.value = dest;
    form.appendChild(inp);
    const act = document.createElement('input');
    act.type = 'hidden';
    act.name = 'action';
    act.value = 'bulk_move';
    form.appendChild(act);
    form.submit();
});

qs('#btnConfirmBulkCopy')?.addEventListener('click', () => {
    const dest = qs('#bulkCopyDest').value;
    const form = qs('#bulkForm');
    const inp = document.createElement('input');
    inp.type = 'hidden';
    inp.name = 'bulk_dest';
    inp.value = dest;
    form.appendChild(inp);
    const act = document.createElement('input');
    act.type = 'hidden';
    act.name = 'action';
    act.value = 'bulk_copy';
    form.appendChild(act);
    form.submit();
});

qs('#btnConfirmBulkChmod')?.addEventListener('click', () => {
    const mode = qs('#bulkChmodMode').value;
    const rec = qs('#bulkChmodRecursive').checked ? '1' : '';
    const form = qs('#bulkForm');
    const inp1 = document.createElement('input');
    inp1.type = 'hidden'; inp1.name = 'bulk_mode'; inp1.value = mode; form.appendChild(inp1);
    const inp2 = document.createElement('input');
    inp2.type = 'hidden'; inp2.name = 'bulk_recursive'; inp2.value = rec; form.appendChild(inp2);
    const act = document.createElement('input');
    act.type = 'hidden'; act.name = 'action'; act.value = 'bulk_chmod'; form.appendChild(act);
    form.submit();
});

qs('#btnBulkDelete')?.addEventListener('click', (e) => {
    const count = rowChecks().filter(c => c.checked).length;
    if (!confirm(`¿Eliminar definitivamente los ${count} elementos seleccionados?`)) {
        e.preventDefault();
    }
});

function updateChmodFromMatrix() {
    let u = 0, g = 0, o = 0;
    if (qs('#u_r').checked) u += 4;
    if (qs('#u_w').checked) u += 2;
    if (qs('#u_x').checked) u += 1;
    if (qs('#g_r').checked) g += 4;
    if (qs('#g_w').checked) g += 2;
    if (qs('#g_x').checked) g += 1;
    if (qs('#o_r').checked) o += 4;
    if (qs('#o_w').checked) o += 2;
    if (qs('#o_x').checked) o += 1;
    const mode = `0${u}${g}${o}`;
    qs('#chmodModeInput').value = mode;
}

function setChmodMatrixFromOctal(oct) {
    oct = oct.replace(/[^0-7]/g, '');
    while (oct.length < 3) oct = '0' + oct;
    const [u, g, o] = oct.slice(-3).split('').map(Number);
    qs('#u_r').checked = !!(u & 4);
    qs('#u_w').checked = !!(u & 2);
    qs('#u_x').checked = !!(u & 1);
    qs('#g_r').checked = !!(g & 4);
    qs('#g_w').checked = !!(g & 2);
    qs('#g_x').checked = !!(g & 1);
    qs('#o_r').checked = !!(o & 4);
    qs('#o_w').checked = !!(o & 2);
    qs('#o_x').checked = !!(o & 1);
    qs('#chmodModeInput').value = '0' + oct.slice(-3);
}

qsa('.chmod-matrix input[type="checkbox"]').forEach(c => {
    c.addEventListener('change', updateChmodFromMatrix);
});
qs('#chmodModeInput')?.addEventListener('input', (e) => {
    setChmodMatrixFromOctal(e.target.value);
});
qsa('.chmod-tag').forEach(tag => {
    tag.addEventListener('click', () => {
        setChmodMatrixFromOctal(tag.dataset.mode);
    });
});

document.addEventListener('click', (e) => {
    const trigger = e.target.closest('.preview-trigger');
    if (trigger) {
        e.preventDefault();
        const cat = trigger.dataset.category;
        const name = trigger.dataset.name;
        const rel = trigger.dataset.rel;
        const rawUrl = `?raw=${encodeURIComponent(rel)}`;
        const dlBtn = qs('#previewDownloadBtn');
        if (dlBtn) dlBtn.href = rawUrl;

        qs('#previewTitle').textContent = name;
        const container = qs('#previewContainer');
        container.innerHTML = '';

        if (cat === 'image') {
            const img = document.createElement('img');
            img.src = rawUrl;
            img.className = 'preview-media';
            container.appendChild(img);
        } else if (cat === 'video') {
            const vid = document.createElement('video');
            vid.src = rawUrl;
            vid.controls = true;
            vid.autoplay = true;
            vid.className = 'preview-media';
            container.appendChild(vid);
        } else if (cat === 'audio') {
            const wrap = document.createElement('div');
            wrap.className = 'preview-audio-wrap';
            wrap.innerHTML = `<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--primary);margin:0 auto"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg><div style="font-weight:600;margin-top:14px">${name}</div><audio controls autoplay src="${rawUrl}"></audio>`;
            container.appendChild(wrap);
        } else if (cat === 'pdf') {
            const iframe = document.createElement('iframe');
            iframe.src = rawUrl;
            iframe.className = 'preview-pdf';
            container.appendChild(iframe);
        }
        openModal('preview');
    }
});

let activeEditorRel = '';
const nativeEditor = qs('#nativeEditor');

function initCodeMirror() {
    if (window.CodeMirror && !window.cmEditor) {
        window.cmEditor = CodeMirror.fromTextArea(nativeEditor, {
            lineNumbers: true,
            theme: htmlEl.getAttribute('data-theme') === 'dark' ? 'material-darker' : 'default',
            mode: 'application/x-httpd-php',
            indentUnit: 4,
            indentWithTabs: false,
            lineWrapping: true,
            autoCloseBrackets: true,
            matchBrackets: true
        });
        window.cmEditor.on('cursorActivity', () => {
            const pos = window.cmEditor.getCursor();
            qs('#editorCursorPosition').textContent = `Línea ${pos.line + 1}, Columna ${pos.ch + 1}`;
        });
    }
}

function detectEditorMode(filename) {
    const ext = filename.split('.').pop().toLowerCase();
    const map = {
        'js': 'javascript', 'json': 'javascript', 'ts': 'javascript',
        'html': 'htmlmixed', 'htm': 'htmlmixed',
        'css': 'css', 'scss': 'css',
        'php': 'application/x-httpd-php',
        'py': 'python',
        'md': 'markdown',
        'xml': 'xml', 'svg': 'xml',
        'c': 'clike', 'cpp': 'clike', 'java': 'clike', 'cs': 'clike'
    };
    return map[ext] || 'text/plain';
}

document.addEventListener('click', (e) => {
    const trigger = e.target.closest('.edit-trigger');
    if (trigger) {
        e.preventDefault();
        const rel = trigger.dataset.rel;
        const name = trigger.dataset.name;
        activeEditorRel = rel;
        qs('#editorFileName').textContent = name;
        qs('#editorStatusBadge').textContent = 'Cargando...';

        fetch(`?get_content=${encodeURIComponent(rel)}`)
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    showToast(data.error || 'Error al cargar', 'bad');
                    return;
                }
                initCodeMirror();
                const mode = detectEditorMode(name);
                if (window.cmEditor) {
                    window.cmEditor.setValue(data.content);
                    window.cmEditor.setOption('mode', mode);
                    setTimeout(() => window.cmEditor.refresh(), 100);
                } else {
                    nativeEditor.value = data.content;
                }
                qs('#editorFileDetails').textContent = `${data.size} • Modificado: ${data.mtime}`;
                qs('#editorStatusBadge').textContent = 'Listo';
                openModal('editor');
            })
            .catch(() => showToast('Error al conectar con el servidor', 'bad'));
    }
});

function saveEditorContent() {
    if (!activeEditorRel) return;
    const content = window.cmEditor ? window.cmEditor.getValue() : nativeEditor.value;
    qs('#editorStatusBadge').textContent = 'Guardando...';

    const formData = new FormData();
    formData.append('csrf', CSRF);
    formData.append('action', 'save');
    formData.append('file', activeEditorRel);
    formData.append('content', content);
    formData.append('ajax', '1');

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                qs('#editorStatusBadge').textContent = `Guardado (${data.mtime})`;
                showToast(`Guardado correctamente a las ${data.mtime}`, 'ok');
            } else {
                qs('#editorStatusBadge').textContent = 'Error';
                showToast(data.error || 'Error al guardar', 'bad');
            }
        })
        .catch(() => {
            qs('#editorStatusBadge').textContent = 'Error de red';
            showToast('Error de red al intentar guardar', 'bad');
        });
}

qs('#btnEditorSave')?.addEventListener('click', saveEditorContent);
document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        if (qs('#modal-editor').classList.contains('show')) {
            e.preventDefault();
            saveEditorContent();
        }
    }
});

const btnUploadTrigger = qs('#btnUploadTrigger');
const globalFileInput = qs('#globalFileInput');
const dropOverlay = qs('#dropOverlay');

if (btnUploadTrigger && globalFileInput) {
    btnUploadTrigger.addEventListener('click', () => globalFileInput.click());
    globalFileInput.addEventListener('change', () => {
        if (globalFileInput.files.length) uploadFileList(globalFileInput.files);
    });
}

window.addEventListener('dragover', (e) => {
    e.preventDefault();
    if (dropOverlay) dropOverlay.classList.add('active');
});
window.addEventListener('dragleave', (e) => {
    if (e.relatedTarget === null && dropOverlay) {
        dropOverlay.classList.remove('active');
    }
});
window.addEventListener('drop', (e) => {
    e.preventDefault();
    if (dropOverlay) dropOverlay.classList.remove('active');
    if (e.dataTransfer && e.dataTransfer.files.length) {
        uploadFileList(e.dataTransfer.files);
    }
});

function uploadFileList(files) {
    openModal('upload');
    const pBar = qs('#uploadProgressBar');
    const pPct = qs('#uploadProgressPct');
    const pStatus = qs('#uploadStatusText');

    pBar.style.width = '0%';
    pPct.textContent = '0%';
    pStatus.textContent = `Subiendo ${files.length} archivo(s)...`;

    const formData = new FormData();
    formData.append('csrf', CSRF);
    formData.append('p', CWD_REL);
    formData.append('action', 'upload');
    formData.append('ajax', '1');
    for (let i = 0; i < files.length; i++) {
        formData.append('files[]', files[i]);
    }

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.href, true);
    xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            pBar.style.width = `${pct}%`;
            pPct.textContent = `${pct}%`;
        }
    };
    xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 400) {
            pBar.style.width = '100%';
            pPct.textContent = '100%';
            showToast('Subida completada con éxito', 'ok');
            setTimeout(() => window.location.reload(), 400);
        } else {
            closeModals();
            showToast('Error al subir archivos (HTTP ' + xhr.status + ')', 'bad');
        }
    };
    xhr.onerror = () => {
        closeModals();
        showToast('Error de conexión durante la subida', 'bad');
    };
    xhr.send(formData);
}

qs('#btnRefreshTerminal')?.addEventListener('click', () => {
    const frame = qs('#terminalFrame');
    if (frame) frame.src = frame.src;
});
</script>
</body>
</html>
