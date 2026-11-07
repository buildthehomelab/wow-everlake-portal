<?php
/**
 * Admin invite codes, for the first players (after that, players make their own on the site).
 *
 *   php bin/invite.php new [count] [note]   make codes
 *   php bin/invite.php list                 every code, who made it and who used it
 *   php bin/invite.php revoke CODE          cancel an unused code
 *
 * In Docker: docker compose exec portal php bin/invite.php new 3 "first testers"
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/app.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$cmd = $argv[1] ?? '';

switch ($cmd) {
    case 'new':
        $count = max(1, (int) ($argv[2] ?? 1));
        for ($i = 0; $i < $count; $i++) {
            echo new_code(null, (string) ($argv[3] ?? '')), PHP_EOL;
        }
        break;

    case 'list':
        $rows = portal_db()->query('SELECT * FROM invites ORDER BY created_at')->fetchAll();
        foreach ($rows as $r) {
            $state = $r['revoked_at'] ? 'cancelled' : ($r['used_at'] ? 'used by account ' . $r['used_by'] . ' ' . gmdate('Y-m-d', (int) $r['used_at']) : 'open');
            printf("%s  %s  by %s  %s%s\n", format_code($r['code']), gmdate('Y-m-d', (int) $r['created_at']),
                $r['created_by'] === null ? 'admin' : 'account ' . $r['created_by'], $state, $r['note'] !== '' ? '  (' . $r['note'] . ')' : '');
        }
        break;

    case 'revoke':
        $q = portal_db()->prepare('UPDATE invites SET revoked_at = ? WHERE code = ? AND used_at IS NULL AND revoked_at IS NULL');
        $q->execute([time(), normalise_code((string) ($argv[2] ?? ''))]);
        echo $q->rowCount() ? "cancelled\n" : "no open code like that\n";
        break;

    default:
        fwrite(STDERR, "usage: php bin/invite.php new [count] [note] | list | revoke CODE\n");
        exit(1);
}
