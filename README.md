# Everlake portal

The website for [Project Everlake](https://github.com/buildthehomelab/wow-project-everlake). It does three things:

- **Sign in** with an Everlake account: the same e-mail and password as the game.
- **Join with an invite code.** There's no open sign-up. Every player can make invite codes for friends, so the
  community grows itself. Each code makes one account, and a player can hold 5 unused codes at a time.
- **Download the launcher**, once signed in. The page picks the build for the visitor's computer: Windows, or a Mac
  with Apple silicon. Intel Macs, Linux and phones get a "not supported" note. The launcher then downloads the game.

It's a few plain PHP files with no framework and no dependencies.

The look follows the cel-shaded style of WoW Forever's class art, drawn in code with no game art: a flat field in
one colour per visit (fel, fire, holy, nature or frost; add `?school=fire` to pick one) and a glowing fireball that
flickers between three drawn frames. It stays still for visitors who turn motion off.

## How it works

- **Accounts are the core's own.** Joining writes a Battle.net account into TrinityCore's `auth` database, plus its
  game account `<id>#1`, exactly as `bnetaccount create` does: SRP6 v2 (PBKDF2-SHA512), case-sensitive passwords up
  to 128 characters. Signing in checks the password against the same verifier, and banned accounts can't sign in.
- **The portal's own state** (invite codes, failed sign-ins) is a small SQLite file in the `portal-data` volume.
- **Downloads** come from a host folder mounted read-only. Only signed-in players get them.
- **Sign-in throttle:** 10 failures per address or 5 per e-mail in 15 minutes, then a wait. Wrong invite codes
  count too.

| Path | What it is |
|---|---|
| `public/index.php` | The whole site: sign in, join, download, invites |
| `public/download.php` | Sends a launcher build to a signed-in player |
| `public/spell.js` | Draws the fireball |
| `public/platform.js` | Tells an Apple silicon Mac from an Intel one, which the User-Agent can't |
| `src/app.php` | Config, databases, sessions, accounts, invites |
| `src/srp.php` | The core's password verifiers |
| `bin/invite.php` | Admin invite codes: `new`, `list`, `revoke` |
| `bin/dev.sh` | Runs it locally with SQLite in place of the server's database |
| `tests/srp_test.php` | Checks the verifiers against `tests/srp_reference.py`, a separate port of the core's C++ |

## Run it

Needs the Everlake server's Docker stack on the same machine, and Traefik with a `websecure` entrypoint and a
`letsencrypt` resolver.

1. **Config:**

   ```sh
   cp .env.example .env
   cp docker/create-db-user.sql.example docker/create-db-user.sql
   ```

   Pick a database password and put it in both files (`AUTH_PASSWORD` and `CHANGE_ME`). Set `PORTAL_HOST` and
   `TRAEFIK_NETWORK`. `EVERLAKE_NETWORK` is the server's network (`docker network ls`, usually
   `everlake_everlake`).

2. **The database user.** It can only make and read accounts in `auth`. Run this from the Everlake server's folder:

   ```sh
   docker compose exec -T mysql mysql -uroot -p < /path/to/wow-everlake-portal/docker/create-db-user.sql
   ```

3. **The launcher builds:** put them in `./downloads` (or `DOWNLOADS_HOST_DIR`) as `Everlake-Setup.exe` and
   `Everlake.dmg` (or the names in `DOWNLOAD_WINDOWS` / `DOWNLOAD_MACOS`). A missing file shows as "coming soon".

4. **Start it:**

   ```sh
   docker compose up -d --build
   ```

5. **The first invite codes** (after that, players make their own):

   ```sh
   docker compose exec portal php bin/invite.php new 3 "first players"
   docker compose exec portal php bin/invite.php list
   ```

   An invite link is `https://PORTAL_HOST/?code=XXXX-XXXX-XXXX`. The site's **copy link** button makes one.

## Try it locally

PHP 8.3 or newer with `gmp` and `pdo_sqlite`. No server or Docker is needed:

```sh
bin/dev.sh invite   # prints an invite code
bin/dev.sh          # http://127.0.0.1:8090
```

Put any file at `dev/downloads/Everlake.dmg` to try a download. Run the tests with `php tests/srp_test.php`.

## License

GPL-2.0, see [LICENSE](LICENSE). The verifier code started from
[masterking32/WoWSimpleRegistration](https://github.com/masterking32/WoWSimpleRegistration), which took it from
TrinityCore's account-creator.
