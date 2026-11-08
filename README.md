# Everlake portal

The website for [Project Everlake](https://github.com/buildthehomelab/wow-project-everlake). It does three things:

- **Sign in** with an Everlake account: the same e-mail and password as the game.
- **Join with an invite code.** There's no open sign-up. Every player can make invite codes for friends, so the
  community grows itself. Each code makes one account, and a player can hold 5 unused codes at a time.
- **Download the launcher**, once signed in. The page picks the build for the visitor's computer: Windows, or a Mac
  with Apple silicon. Intel Macs, Linux and phones get a "not supported" note. The launcher then downloads the game.

It's a few plain PHP files with no framework and no dependencies. Beside it runs **Everlake's own CDN** (the `cdn`
service, see [below](#the-cdn)), which the launcher downloads the game from when Blizzard's CDN is down or slow.

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
   docker compose exec -u www-data portal php bin/invite.php new 3 "first players"
   docker compose exec -u www-data portal php bin/invite.php list
   ```

   An invite link is `https://PORTAL_HOST/?code=XXXX-XXXX-XXXX`. The site's **copy link** button makes one.

### As a TrueNAS app

To start, stop and restart it from the TrueNAS Apps screen, make it a custom app that includes this folder's
compose file. Do steps 1 to 3 above first.

1. Build the images from a shell: `docker compose build` (the portal's and the [CDN](#the-cdn)'s; make the CDN's
   folders before the app starts). If the portal already runs from a shell, stop it with `docker compose down`
   (its data volume stays).
2. **Apps → Discover Apps → ⋮ → Install via YAML**, name it `everlake-portal`, and paste:

   ```yaml
   include:
     - /path/to/wow-everlake-portal/docker-compose.yml
   ```

3. Make invite codes with `docker exec -u www-data ix-everlake-portal-portal-1 php bin/invite.php ...`.

To update: `git pull` and `docker compose build` in a shell, then **Restart** the app in the Apps screen.

## The CDN

The `cdn` service is the launcher's `mirror` command, built from
[wow-everlake-launcher](https://github.com/buildthehomelab/wow-everlake-launcher) (design:
`docs/cdn-and-peers.md` there). It answers like Blizzard's CDN. Everything it fetches from Blizzard is checked
against its hash and kept in `./cdn`, and on start it fetches the launcher's pinned build for Mac and Windows
(enUS, about 89 GB) in the background, at most `CDN_RATE_MB` MB/s. A stopped fetch picks up where it left off.
Our own patched builds in `./cdn-overlay` (wow-project-everlake's `cdn-build` output) are served first.

Before the first start, make its folders, owned by the user it runs as (`CDN_UID`, default 950):

```sh
mkdir -p cdn cdn-overlay && chown 950:950 cdn cdn-overlay
```

Then it starts with the portal, on port `CDN_PORT` (8088): `docker compose up -d --build` from a shell, or, for the
TrueNAS app, `docker compose build` and **Restart** the app. Follow the fetch with `docker compose logs -f cdn`
(`docker logs -f ix-everlake-portal-cdn-1` for the app). A launcher uses it first, with Blizzard after it:

```sh
everlake-launcher install --dir <folder> --cdn <server>:8088
```

**Outside the house.** With `CDN_PUBLIC=true` in `.env` it is also served on the portal's own name under `/tpr/`
(the CDN's paths), beside the portal and through the same Traefik route, so the Cloudflare tunnel carries it with
no new name or port. Players then use `--cdn https://PORTAL_HOST`. At home the name goes straight to Traefik.

It never forces writes to disk, so the first fetch doesn't stall a database on the same disk. On ZFS, a dataset of
its own for `./cdn` with `recordsize=1M` and `compression=off` suits it (the files are already compressed).

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
