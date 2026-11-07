#!/bin/sh
# Runs the portal on this computer with SQLite stand-ins for the auth database: no server, no Docker.
#   bin/dev.sh            serve on http://127.0.0.1:8090
#   bin/dev.sh invite     make an invite code for the local copy
set -e
cd "$(dirname "$0")/.."
mkdir -p dev/data dev/downloads
php -r '$db = new PDO("sqlite:dev/auth.sqlite"); $db->exec(file_get_contents("tests/dev-auth.sql"));'

export AUTH_DSN="sqlite:$PWD/dev/auth.sqlite" AUTH_USER='' AUTH_PASSWORD=''
export PORTAL_DATA_DIR="$PWD/dev/data" DOWNLOAD_DIR="$PWD/dev/downloads"

if [ "$1" = invite ]; then
  exec php bin/invite.php new 1 dev
fi
exec php -S 127.0.0.1:"${PORT:-8090}" -t public
