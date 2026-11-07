<?php
/**
 * Everything the portal does, in one place: config, the two databases, sessions, accounts and invites.
 *
 * - The auth database is TrinityCore's own (`auth`): accounts are made and checked there, the way the core does.
 * - The portal's own state (invites, failed logins) lives in a small SQLite file under PORTAL_DATA_DIR.
 */

declare(strict_types=1);

require_once __DIR__ . '/srp.php';

const INVITE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I
const PASSWORD_MIN = 8;
const PASSWORD_MAX = 128;  // MAX_BNET_PASS_STR
const EMAIL_MAX = 320;     // MAX_BNET_EMAIL_STR
const GAME_PASS_MAX = 16;  // MAX_PASS_STR: the linked game account keeps the first 16 characters

function env(string $name, string $default = ''): string
{
    $v = getenv($name);
    return ($v === false || $v === '') ? $default : $v;
}

function site_name(): string
{
    return env('SITE_NAME', 'Everlake');
}

function invites_per_account(): int
{
    return max(0, (int) env('INVITES_PER_ACCOUNT', '5'));
}

// ---------------------------------------------------------------- databases

function auth_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            env('AUTH_DSN', 'mysql:host=mysql;port=3306;dbname=auth;charset=utf8mb4'),
            env('AUTH_USER', 'everlake_portal') ?: null,
            env('AUTH_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function data_dir(): string
{
    return rtrim(env('PORTAL_DATA_DIR', '/data'), '/');
}

function portal_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . data_dir() . '/portal.sqlite', null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
        $pdo->exec('CREATE TABLE IF NOT EXISTS invites (
            code TEXT PRIMARY KEY,
            created_by INTEGER,          -- battlenet_accounts.id, NULL = made by an admin
            created_at INTEGER NOT NULL,
            used_by INTEGER,             -- battlenet_accounts.id of the new player (0 while being claimed)
            used_at INTEGER,
            revoked_at INTEGER,
            note TEXT NOT NULL DEFAULT ""
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS invites_by ON invites (created_by)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS failures (ip TEXT NOT NULL, email TEXT NOT NULL, at INTEGER NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS failures_at ON failures (at)');
    }
    return $pdo;
}

// ---------------------------------------------------------------- request helpers

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** The player's address. Behind Traefik (a private REMOTE_ADDR) it's the last X-Forwarded-For entry. */
function client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $private = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if ($private) {
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', env('CLIENT_IP_HEADER', 'X-Forwarded-For')));
        if (!empty($_SERVER[$header])) {
            $parts = array_map('trim', explode(',', $_SERVER[$header]));
            $last = end($parts);
            if (filter_var($last, FILTER_VALIDATE_IP)) {
                return $last;
            }
        }
    }
    return $remote;
}

function is_https(): bool
{
    return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function start_session(): void
{
    $dir = data_dir() . '/sessions';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $lifetime = 30 * 86400;
    session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.use_strict_mode', '1');
    session_name('everlake');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
}

function csrf_ok(): bool
{
    return is_string($_POST['csrf'] ?? null) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
}

function flash(string $kind, string $text): void
{
    $_SESSION['flash'] = [$kind, $text];
}

function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---------------------------------------------------------------- throttle

const THROTTLE_WINDOW = 900;   // 15 minutes
const THROTTLE_PER_IP = 10;
const THROTTLE_PER_EMAIL = 5;

function throttled(string $ip, string $email = ''): bool
{
    $db = portal_db();
    $since = time() - THROTTLE_WINDOW;
    $db->prepare('DELETE FROM failures WHERE at < ?')->execute([$since]);
    $q = $db->prepare('SELECT COUNT(*) FROM failures WHERE ip = ? AND at >= ?');
    $q->execute([$ip, $since]);
    if ((int) $q->fetchColumn() >= THROTTLE_PER_IP) {
        return true;
    }
    if ($email !== '') {
        $q = $db->prepare('SELECT COUNT(*) FROM failures WHERE email = ? AND at >= ?');
        $q->execute([upper_latin($email), $since]);
        return (int) $q->fetchColumn() >= THROTTLE_PER_EMAIL;
    }
    return false;
}

function record_failure(string $ip, string $email = ''): void
{
    portal_db()->prepare('INSERT INTO failures (ip, email, at) VALUES (?, ?, ?)')
        ->execute([$ip, upper_latin($email), time()]);
}

// ---------------------------------------------------------------- accounts

/** A Battle.net account by e-mail, or null. */
function find_account(string $email): ?array
{
    $q = auth_db()->prepare('SELECT id, email, srp_version, HEX(salt) AS salt, HEX(verifier) AS verifier FROM battlenet_accounts WHERE email = ?');
    $q->execute([upper_latin($email)]);
    $row = $q->fetch();
    if (!$row) {
        return null;
    }
    $row['salt'] = hex2bin($row['salt']);
    $row['verifier'] = hex2bin($row['verifier']);
    return $row;
}

function account_banned(int $id): bool
{
    $q = auth_db()->prepare('SELECT 1 FROM battlenet_account_bans WHERE id = ? AND (unbandate > ? OR unbandate = bandate) LIMIT 1');
    $q->execute([$id, time()]);
    return (bool) $q->fetchColumn();
}

/** Returns the account row when the e-mail and password match, else null. */
function check_login(string $email, string $password): ?array
{
    $acc = find_account($email);
    if ($acc === null) {
        bnet_v2_verifier($email, $password, random_bytes(32)); // same time whether or not the account exists
        return null;
    }
    $ok = bnet_check_password($acc['email'], $password, (int) $acc['srp_version'], (string) $acc['salt'], (string) $acc['verifier']);
    return $ok ? $acc : null;
}

/**
 * Makes a Battle.net account plus its game account "<id>#1", as `bnetaccount create` does.
 * Returns the new battlenet_accounts.id.
 */
function create_account(string $email, string $password): int
{
    $email = upper_latin($email);
    $db = auth_db();
    $db->beginTransaction();
    try {
        $salt = random_bytes(32);
        // Binary values go in as hex, so no connection charset ever touches them.
        $db->prepare('INSERT INTO battlenet_accounts (email, srp_version, salt, verifier) VALUES (?, ?, UNHEX(?), UNHEX(?))')
            ->execute([$email, BNET_SRP_VERSION, bin2hex($salt), bin2hex(bnet_v2_verifier($email, $password, $salt))]);
        $bnetId = (int) $db->lastInsertId();

        $gameName = $bnetId . '#1';
        $gamePass = upper_latin(mb_substr($password, 0, GAME_PASS_MAX, 'UTF-8'));
        $gameSalt = random_bytes(32);
        $db->prepare('INSERT INTO account (username, salt, verifier, reg_mail, email, joindate, battlenet_account, battlenet_index)
                      VALUES (?, UNHEX(?), UNHEX(?), ?, ?, ?, ?, 1)')
            ->execute([$gameName, bin2hex($gameSalt), bin2hex(grunt_verifier($gameName, $gamePass, $gameSalt)), $email, $email, gmdate('Y-m-d H:i:s'), $bnetId]);
        $gameId = (int) $db->lastInsertId();

        $db->prepare('INSERT INTO realmcharacters (realmid, acctid, numchars) SELECT id, ?, 0 FROM realmlist')->execute([$gameId]);
        $db->commit();
        return $bnetId;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------- invites

function normalise_code(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
}

function format_code(string $raw): string
{
    return implode('-', str_split($raw, 4));
}

function new_code(?int $createdBy, string $note = ''): string
{
    $raw = '';
    for ($i = 0; $i < 12; $i++) {
        $raw .= INVITE_ALPHABET[random_int(0, strlen(INVITE_ALPHABET) - 1)];
    }
    portal_db()->prepare('INSERT INTO invites (code, created_by, created_at, note) VALUES (?, ?, ?, ?)')
        ->execute([$raw, $createdBy, time(), $note]);
    return format_code($raw);
}

function open_invite_count(int $accountId): int
{
    $q = portal_db()->prepare('SELECT COUNT(*) FROM invites WHERE created_by = ? AND used_at IS NULL AND revoked_at IS NULL');
    $q->execute([$accountId]);
    return (int) $q->fetchColumn();
}

/** A player's own codes, newest first. */
function invites_of(int $accountId): array
{
    $q = portal_db()->prepare('SELECT code, created_at, used_at, revoked_at FROM invites WHERE created_by = ? ORDER BY created_at DESC LIMIT 50');
    $q->execute([$accountId]);
    return $q->fetchAll();
}

/** Takes an unused code so no one else can use it at the same time. True when it was free. */
function claim_invite(string $code): bool
{
    $q = portal_db()->prepare('UPDATE invites SET used_at = ?, used_by = 0 WHERE code = ? AND used_at IS NULL AND revoked_at IS NULL');
    $q->execute([time(), normalise_code($code)]);
    return $q->rowCount() === 1;
}

function finish_invite(string $code, int $accountId): void
{
    portal_db()->prepare('UPDATE invites SET used_by = ? WHERE code = ?')->execute([$accountId, normalise_code($code)]);
}

function release_invite(string $code): void
{
    portal_db()->prepare('UPDATE invites SET used_at = NULL, used_by = NULL WHERE code = ? AND used_by = 0')->execute([normalise_code($code)]);
}

function revoke_invite(string $code, int $accountId): bool
{
    $q = portal_db()->prepare('UPDATE invites SET revoked_at = ? WHERE code = ? AND created_by = ? AND used_at IS NULL AND revoked_at IS NULL');
    $q->execute([time(), normalise_code($code), $accountId]);
    return $q->rowCount() === 1;
}

// ---------------------------------------------------------------- downloads

/** The launcher builds offered, by platform key. A missing file shows as "not ready yet". */
function downloads(): array
{
    $dir = rtrim(env('DOWNLOAD_DIR', '/downloads'), '/');
    $list = [
        'windows' => ['label' => 'Windows', 'detail' => 'Windows 10 or 11, 64-bit', 'file' => env('DOWNLOAD_WINDOWS', 'Everlake-Setup.exe')],
        'macos' => ['label' => 'macOS', 'detail' => 'Apple silicon (M1 or newer)', 'file' => env('DOWNLOAD_MACOS', 'Everlake.dmg')],
    ];
    foreach ($list as $key => $d) {
        $path = $dir . '/' . basename($d['file']);
        $list[$key]['path'] = is_file($path) ? $path : null;
        $list[$key]['size'] = is_file($path) ? filesize($path) : null;
    }
    return $list;
}

/** Coarse platform from the User-Agent; the page refines Intel vs Apple silicon Macs in the browser. */
function platform_from_ua(string $ua): string
{
    return match (true) {
        (bool) preg_match('/iPhone|iPad|iPod|Android/i', $ua) => 'mobile',
        (bool) preg_match('/Windows NT/i', $ua) => 'windows',
        (bool) preg_match('/Macintosh|Mac OS X/i', $ua) => 'macos',
        (bool) preg_match('/Linux|X11|CrOS/i', $ua) => 'linux',
        default => 'unknown',
    };
}
