<?php
/**
 * The whole portal: sign in, join with an invite, download the launcher, hand out invites.
 * POSTs redirect back here (post/redirect/get) with a one-line message.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/app.php';

start_session();

$me = isset($_SESSION['account_id']) ? ['id' => (int) $_SESSION['account_id'], 'email' => (string) $_SESSION['email']] : null;
if ($me !== null && account_banned($me['id'])) {
    $_SESSION = [];
    session_regenerate_id(true);
    $me = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_post($me);
    header('Location: ./' . (($_POST['action'] ?? '') === 'join' && !isset($_SESSION['account_id']) ? '?join=1' : ''), true, 303);
    exit;
}

function handle_post(?array $me): void
{
    if (!csrf_ok()) {
        flash('error', 'Your session ran out. Please try again.');
        return;
    }
    $ip = client_ip();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (throttled($ip, $email)) {
            flash('error', 'Too many tries. Wait 15 minutes and try again.');
            return;
        }
        $acc = $email !== '' && $password !== '' ? check_login($email, $password) : null;
        if ($acc === null) {
            record_failure($ip, $email);
            flash('error', 'That e-mail and password don\'t match an account.');
            return;
        }
        if (account_banned((int) $acc['id'])) {
            flash('error', 'This account is banned.');
            return;
        }
        sign_in((int) $acc['id'], (string) $acc['email']);
        return;
    }

    if ($action === 'join') {
        $code = (string) ($_POST['code'] ?? '');
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $_SESSION['join'] = ['code' => $code, 'email' => $email];
        if (throttled($ip)) {
            flash('error', 'Too many tries. Wait 15 minutes and try again.');
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > EMAIL_MAX) {
            flash('error', 'Enter a valid e-mail address. It\'s what you log in to the game with.');
            return;
        }
        $len = mb_strlen($password, 'UTF-8');
        if ($len < PASSWORD_MIN || $len > PASSWORD_MAX) {
            flash('error', 'Your password needs ' . PASSWORD_MIN . ' to ' . PASSWORD_MAX . ' characters.');
            return;
        }
        if ($password !== (string) ($_POST['password2'] ?? '')) {
            flash('error', 'The two passwords aren\'t the same.');
            return;
        }
        if (find_account($email) !== null) {
            flash('error', 'There\'s already an account with that e-mail. Sign in instead.');
            return;
        }
        if (!claim_invite($code)) {
            record_failure($ip);
            flash('error', 'That invite code isn\'t valid or has been used.');
            return;
        }
        try {
            $id = create_account($email, $password);
        } catch (Throwable $e) {
            release_invite($code);
            error_log('everlake-portal: account creation failed: ' . $e->getMessage());
            flash('error', 'Something went wrong making your account. Your invite code still works; try again later.');
            return;
        }
        finish_invite($code, $id);
        unset($_SESSION['join']);
        sign_in($id, upper_latin($email));
        flash('ok', 'Welcome to ' . site_name() . '! Your account is ready.');
        return;
    }

    if ($me === null) {
        flash('error', 'Please sign in first.');
        return;
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        return;
    }

    if ($action === 'invite') {
        $cap = invites_per_account();
        if ($cap > 0 && open_invite_count($me['id']) >= $cap) {
            flash('error', "You have $cap unused codes. Give those out (or cancel one) before making more.");
            return;
        }
        $code = new_code($me['id']);
        flash('ok', "New invite code: $code");
        return;
    }

    if ($action === 'revoke') {
        $code = (string) ($_POST['code'] ?? '');
        if (revoke_invite($code, $me['id'])) {
            flash('ok', 'Code ' . format_code(normalise_code($code)) . ' cancelled.');
        } else {
            flash('error', 'That code can\'t be cancelled.');
        }
        return;
    }
}

function sign_in(int $id, string $email): void
{
    session_regenerate_id(true);
    $_SESSION['account_id'] = $id;
    $_SESSION['email'] = $email;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// ---------------------------------------------------------------- page

$flash = take_flash();
$csrf = $_SESSION['csrf'];
$join = $_SESSION['join'] ?? ['code' => (string) ($_GET['code'] ?? ''), 'email' => ''];
$showJoin = $me === null && (isset($_GET['join']) || isset($_GET['code']));
$platform = platform_from_ua($_SERVER['HTTP_USER_AGENT'] ?? '');
$downloads = $me !== null ? downloads() : [];
$invites = $me !== null ? invites_of($me['id']) : [];
$cap = invites_per_account();
$site = site_name();
// One school of magic per visit sets the page's colour, as each class banner has its own.
$schools = ['fel', 'fire', 'holy', 'nature', 'frost'];
$school = in_array($_GET['school'] ?? '', $schools, true) ? $_GET['school'] : $schools[random_int(0, count($schools) - 1)];

function size_text(?int $bytes): string
{
    if ($bytes === null) {
        return '';
    }
    return $bytes >= 1048576 ? round($bytes / 1048576) . ' MB' : max(1, round($bytes / 1024)) . ' KB';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= h($site) ?></title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="style.css">
</head>
<body data-school="<?= h($school) ?>">
<div class="spell" aria-hidden="true"></div>
<main class="card">
    <h1><?= h($site) ?></h1>

    <?php if ($flash): ?>
        <p class="flash <?= h($flash[0]) ?>" role="status"><?= h($flash[1]) ?></p>
    <?php endif; ?>

<?php if ($me === null && !$showJoin): ?>
    <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="login">
        <label>E-mail <input type="email" name="email" autocomplete="username" required autofocus></label>
        <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
        <button type="submit">Sign in</button>
    </form>
    <p class="small">New here? You need an invite code from a player. <a href="?join=1">Join with a code</a></p>

<?php elseif ($me === null): ?>
    <p class="lead">Join with an invite code from a player.</p>
    <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="join">
        <label>Invite code <input type="text" name="code" value="<?= h($join['code']) ?>" placeholder="XXXX-XXXX-XXXX" autocomplete="off" autocapitalize="characters" required <?= $join['code'] === '' ? 'autofocus' : '' ?>></label>
        <label>E-mail <span class="hint">you log in to the game with it</span><input type="email" name="email" value="<?= h($join['email']) ?>" autocomplete="username" required <?= $join['code'] !== '' ? 'autofocus' : '' ?>></label>
        <label>Password <span class="hint"><?= PASSWORD_MIN ?> or more characters</span><input type="password" name="password" minlength="<?= PASSWORD_MIN ?>" maxlength="<?= PASSWORD_MAX ?>" autocomplete="new-password" required></label>
        <label>Password again <input type="password" name="password2" minlength="<?= PASSWORD_MIN ?>" maxlength="<?= PASSWORD_MAX ?>" autocomplete="new-password" required></label>
        <button type="submit">Make my account</button>
    </form>
    <p class="small">Already have an account? <a href="./">Sign in</a></p>

<?php else: ?>
    <p class="who">Signed in as <strong><?= h(strtolower($me['email'])) ?></strong></p>

    <section id="download" data-platform="<?= h($platform) ?>">
        <h2>Download</h2>
        <?php foreach ($downloads as $key => $d): ?>
            <div class="dl" data-for="<?= h($key) ?>" <?= $key === $platform ? '' : 'hidden' ?>>
                <?php if ($d['path']): ?>
                    <a class="button" href="download.php?platform=<?= h($key) ?>">Download for <?= h($d['label']) ?></a>
                    <p class="small"><?= h($d['detail']) ?> · <?= h(size_text($d['size'])) ?></p>
                <?php else: ?>
                    <span class="button disabled">Launcher for <?= h($d['label']) ?>: coming soon</span>
                    <p class="small"><?= h($d['detail']) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="unsupported" <?= isset($downloads[$platform]) ? 'hidden' : '' ?>>
            <p><strong>This computer isn't supported.</strong> <?= h($site) ?> runs on Windows and on Macs with Apple silicon (M1 or newer).</p>
            <?php if ($platform === 'linux'): ?><p class="small">Linux isn't supported yet.</p><?php endif; ?>
        </div>
        <p class="small mac-note" hidden>We couldn't tell which kind of Mac this is. The game needs Apple silicon (M1 or newer); Intel Macs can't run it.</p>
        <p class="small"><a href="#" id="show-all">Downloading for another computer?</a></p>
        <p class="small">The launcher downloads the game and keeps it up to date. Sign in to it with the same e-mail and password.</p>
    </section>

    <section id="invites">
        <h2>Invite a friend</h2>
        <p class="small">Each code makes one account. <?= $cap > 0 ? "You can have $cap unused codes at a time." : '' ?></p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="invite">
            <button type="submit">Make an invite code</button>
        </form>
        <?php if ($invites): ?>
            <ul class="codes">
                <?php foreach ($invites as $inv): $code = format_code($inv['code']); ?>
                    <li>
                        <code><?= h($code) ?></code>
                        <?php if ($inv['revoked_at']): ?>
                            <span class="state">cancelled</span>
                        <?php elseif ($inv['used_at']): ?>
                            <span class="state used">used <?= h(gmdate('j M Y', (int) $inv['used_at'])) ?></span>
                        <?php else: ?>
                            <button type="button" class="link copy" data-code="<?= h($code) ?>">copy link</button>
                            <form method="post" class="inline">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="code" value="<?= h($inv['code']) ?>">
                                <button type="submit" class="link">cancel</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <form method="post" class="signout">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="link">Sign out</button>
    </form>
<?php endif; ?>
</main>
<script src="spell.js"></script>
<?php if ($me !== null): ?><script src="platform.js"></script><?php endif; ?>
</body>
</html>
