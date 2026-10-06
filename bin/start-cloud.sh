#!/usr/bin/env bash
set -euo pipefail
cd /workspace/oldora
setup=/workspace/.setup
export DOCKER_CONFIG="$setup/docker"
if ! docker image inspect oldora-php-dev >/dev/null 2>&1; then docker load -i "$setup/oldora-php-dev.tar"; fi
if ! docker image inspect mariadb@sha256:1292844148b311e4ed4300022a996d39083f415a963e970cf47cad1b3b18e3a6 >/dev/null 2>&1; then docker load -i "$setup/mariadb-dev.tar"; fi
if ! docker container inspect oldora-db >/dev/null 2>&1; then
  docker run -d --name oldora-db --network host -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 -e MARIADB_DATABASE=oldora_dev -v "$setup/mysql:/var/lib/mysql" mariadb@sha256:1292844148b311e4ed4300022a996d39083f415a963e970cf47cad1b3b18e3a6 --bind-address=127.0.0.1
else
  docker start oldora-db >/dev/null
fi
for attempt in $(seq 1 30); do
  if docker exec oldora-db mariadb-admin ping >/dev/null 2>&1; then break; fi
  sleep 1
done
docker exec oldora-db mariadb-admin ping
if [ ! -e /workspace/.env ]; then
  (umask 077; cat > /workspace/.env <<'EOF'
DB_HOST=127.0.0.1
DB_USER=root
DB_PASS=
DB_NAME=oldora_dev
APP_URL=http://127.0.0.1:8000
VIDEO_PROVIDER=moneyprinterturbo
MONEYPRINTER_API_URL=http://127.0.0.1:8080
EOF
  )
fi
mkdir -p uploads/generated
[ -w uploads/generated ] || { echo 'uploads/generated must be writable by the development user.' >&2; exit 1; }
"$setup/bin/php" bin/setup-development-db.php
if ! curl -fsS http://127.0.0.1:8080/api/v1/tasks >/dev/null; then
  nohup "$setup/MoneyPrinterTurbo/.venv/bin/python" /workspace/oldora/bin/start-video-service.py > "$setup/video-service.log" 2>&1 &
  echo "$!" > "$setup/video-service.pid"
fi
if ! docker container inspect oldora-web >/dev/null 2>&1; then
  docker run -d --name oldora-web --network host -u "$(id -u):$(id -g)" -v /workspace:/workspace -w /workspace/oldora oldora-php-dev php -S 127.0.0.1:8000 -t /workspace/oldora
else
  docker start oldora-web >/dev/null
fi
for attempt in $(seq 1 30); do
  if curl -fsS http://127.0.0.1:8080/api/v1/tasks >/dev/null; then break; fi
  sleep 1
done
curl -fsS http://127.0.0.1:8080/api/v1/tasks >/dev/null
curl -fsS http://127.0.0.1:8000/index.php -o "$setup/landing-smoke.html"
rg -q 'AI Social Growth Automation' "$setup/landing-smoke.html"
echo 'PHP, MariaDB, and video API startup checks passed.'
