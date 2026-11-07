<?php
/**
 * Checks the portal's password verifiers against tests/srp_reference.py, an independent port of the core's C++.
 * Run: php tests/srp_test.php   (needs python3)
 */

declare(strict_types=1);

require __DIR__ . '/../src/srp.php';

$in = '';
$ours = '';
$topBit = 0;
for ($i = 0; $i < 40; $i++) {
    $salt = hash('sha256', "salt$i", true);
    $email = "Player$i@Example.com";
    $pw = "Pass word $i!" . str_repeat('x', $i);
    $game = "$i#1";
    $gamePw = substr($pw, 0, 16);

    $in .= "bnet\t$email\t$pw\t" . bin2hex($salt) . "\n";
    $ours .= 'bnet ' . bin2hex(rtrim(bnet_v2_verifier($email, $pw, $salt), "\0")) . "\n";
    $in .= "game\t$game\t$gamePw\t" . bin2hex($salt) . "\n";
    $ours .= 'game ' . bin2hex(grunt_verifier(strtoupper($game), strtoupper($gamePw), $salt)) . "\n";

    $x = hash_pbkdf2('sha512', bnet_srp_username($email) . ':' . $pw, $salt, 15000, 64, true);
    $topBit += (ord($x[0]) & 0x80) ? 1 : 0;
}

$proc = proc_open(['python3', '-I', __DIR__ . '/srp_reference.py'], [['pipe', 'r'], ['pipe', 'w']], $pipes);
fwrite($pipes[0], $in);
fclose($pipes[0]);
$theirs = stream_get_contents($pipes[1]);
proc_close($proc);

$fail = 0;
if ($ours !== $theirs) {
    echo "FAIL: verifiers differ from the reference\n";
    $fail++;
}
if ($topBit === 0) {
    echo "FAIL: no case has the top bit of x set\n";
    $fail++;
}

$salt = random_bytes(32);
$v = bnet_v2_verifier('a@b.c', 'secret123', $salt);
if (!bnet_check_password('A@B.C', 'secret123', 2, $salt, $v)) {
    echo "FAIL: right password refused\n";
    $fail++;
}
if (bnet_check_password('a@b.c', 'Secret123', 2, $salt, $v)) {
    echo "FAIL: wrong case accepted (v2 passwords are case-sensitive)\n";
    $fail++;
}
if (!bnet_check_password('a@b.c', 'secret123', 2, $salt, rtrim($v, "\0"))) {
    echo "FAIL: unpadded verifier refused\n";
    $fail++;
}

echo $fail ? "$fail failed\n" : "ok: 80 verifiers match the reference ($topBit with the top bit set), checks pass\n";
exit($fail ? 1 : 0);
