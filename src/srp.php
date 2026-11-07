<?php
/**
 * Password verifiers exactly as TrinityCore's `forever` core makes them
 * (src/common/Cryptography/Authentication/SRP6.cpp, src/server/game/Accounts/BattlenetAccountMgr.cpp).
 *
 * - Battle.net account (what players log in with): SRP6 v2, x = PBKDF2-SHA512(15000) of
 *   "HEX(SHA256(EMAIL)):password", read as a signed big-endian number, mod N-1. Verifier little-endian.
 * - Game account "<id>#1" linked to it: the old Grunt SRP6, x = SHA1(salt . SHA1("USER:PASS")).
 *
 * The PHP started from masterking32/WoWSimpleRegistration (from TrinityCore/account-creator).
 */

declare(strict_types=1);

const BNET_SRP_VERSION = 2;
const BNET_V2_N = 'AC6BDB41324A9A9BF166DE5E1389582FAF72B6651987EE07FC3192943DB56050A37329CBB4A099ED8193E0757767A13DD52312AB4B03310DCD7F48A9DA04FD50E8083969EDB767B0CF6095179A163AB3661A05FBD5FAAAE82918A9962F0B93B855F97993EC975EEAA80D740ADBF4FF747359D041D5C33EA71D281E446B14773BCA97B43A23FB801676BD207A436C6481F1D2B9078717461A5B9D32E688F87748544523B524B0D57D5EA77A2775D2ECFA032CFBDBF52FB3786160279004E57AE6AF874E7303CE53299CCC041C7BC308D82A5698F3A8D0C38271AE35F8E9DBFBB694B5C803D89F7AE435DE236D525F54759B65E372FCD68EF20FA7111F9E4AFF73';
const BNET_V1_N = '86A7F6DEEB306CE519770FE37D556F29944132554DED0BD68205E27F3231FEF5A10108238A3150C59CAF7B0B6478691C13A6ACF5E1B5ADAFD4A943D4A21A142B800E8A55F8BFBAC700EB77A7235EE5A609E350EA9FC19F10D921C2FA832E4461B7125D38D254A0BE873DFC27858ACB3F8B9F258461E4373BC3A6C2A9634324AB';
const GRUNT_N = '894B645E89E1535BBDAD5B8B290650530801B18EBFBF5E8FAB3C82872A3E9BB7';

/** The core's Utf8ToUpperOnlyLatin, close enough for e-mail addresses and account names. */
function upper_latin(string $s): string
{
    return strtoupper($s);
}

/** Battle.net SRP "username": upper-case hex SHA-256 of the upper-cased e-mail. */
function bnet_srp_username(string $email): string
{
    return strtoupper(hash('sha256', upper_latin($email)));
}

/** Verifier for a Battle.net account, srp_version 2. Returns 256 bytes, little-endian. */
function bnet_v2_verifier(string $email, string $password, string $salt): string
{
    $N = gmp_init(BNET_V2_N, 16);
    $xBytes = hash_pbkdf2('sha512', bnet_srp_username($email) . ':' . $password, $salt, 15000, 64, true);
    $x = gmp_import($xBytes, 1, GMP_MSW_FIRST | GMP_NATIVE_ENDIAN);
    if (ord($xBytes[0]) & 0x80) {
        $x = gmp_sub($x, gmp_pow(2, 512)); // the client reads it as a signed number
    }
    $x = gmp_mod($x, gmp_sub($N, 1)); // BN_nnmod: never negative
    return le_bytes(gmp_powm(2, $x, $N), 256);
}

/** Verifier for an old srp_version 1 account (made by older cores): case-insensitive, SHA-256. */
function bnet_v1_verifier(string $email, string $password, string $salt): string
{
    $N = gmp_init(BNET_V1_N, 16);
    $inner = hash('sha256', bnet_srp_username($email) . ':' . upper_latin($password), true);
    $x = gmp_import(hash('sha256', $salt . $inner, true), 1, GMP_LSW_FIRST | GMP_NATIVE_ENDIAN);
    return le_bytes(gmp_powm(2, $x, $N), 128);
}

/** Verifier for a game account (Grunt SRP6). Name and password must already be upper-cased. 32 bytes. */
function grunt_verifier(string $username, string $password, string $salt): string
{
    $N = gmp_init(GRUNT_N, 16);
    $x = gmp_import(sha1($salt . sha1($username . ':' . $password, true), true), 1, GMP_LSW_FIRST | GMP_NATIVE_ENDIAN);
    return le_bytes(gmp_powm(7, $x, $N), 32);
}

function le_bytes(\GMP $n, int $size): string
{
    return str_pad(gmp_export($n, 1, GMP_LSW_FIRST | GMP_NATIVE_ENDIAN), $size, "\0", STR_PAD_RIGHT);
}

/** Compares two little-endian verifiers by value, so a stored verifier without zero padding still matches. */
function verifier_equals(string $a, string $b): bool
{
    return hash_equals(rtrim($a, "\0"), rtrim($b, "\0"));
}

/** Checks a password against a battlenet_accounts row (srp_version, salt, verifier). */
function bnet_check_password(string $email, string $password, int $srpVersion, string $salt, string $verifier): bool
{
    return match ($srpVersion) {
        2 => verifier_equals(bnet_v2_verifier($email, $password, $salt), $verifier),
        1 => verifier_equals(bnet_v1_verifier($email, $password, $salt), $verifier),
        default => false,
    };
}
