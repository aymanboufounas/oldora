#!/usr/bin/env bash
set -Eeuo pipefail
cd /workspace/oldora
setup=/workspace/.setup
export DOCKER_CONFIG="$setup/docker"
source /workspace/oldora/bin/cloud-runtime-env.sh
runtime_fingerprint="$(oldora_runtime_fingerprint)"
# Include the server recipe so a prior one-request container also gets upgraded.
runtime_fingerprint="$(printf '%s\n%s\n' "$runtime_fingerprint" 'php-workers=4;init=1' | sha256sum | cut -d' ' -f1)"
web_backup=''
web_paused=false
rollback_web() {
  local result=$?
  if [ "$result" -ne 0 ] && [ -n "$web_backup" ]; then
    if [ "$(docker inspect --format '{{index .Config.Labels "io.oldora.cloud"}}' oldora-web 2>/dev/null || true)" = '1' ]; then
      docker rm -f oldora-web >/dev/null 2>&1 || true
    fi
    docker rename "$web_backup" oldora-web >/dev/null 2>&1 || true
    docker start oldora-web >/dev/null 2>&1 || true
    docker exec -d oldora-web sh -c 'exec php /workspace/oldora/bin/run-workers.php >> /workspace/storage/logs/workers.log 2>&1' >/dev/null 2>&1 || true
    echo 'Startup failed; the previous development web container was restored.' >&2
  elif [ "$result" -ne 0 ] && [ "$web_paused" = true ]; then
    if [ "$(docker inspect --format '{{index .Config.Labels "io.oldora.cloud"}}' oldora-web 2>/dev/null || true)" = '1' ]; then
      docker start oldora-web >/dev/null 2>&1 || true
      docker exec -d oldora-web sh -c 'exec php /workspace/oldora/bin/run-workers.php >> /workspace/storage/logs/workers.log 2>&1' >/dev/null 2>&1 || true
      echo 'Startup failed; the paused development web service was resumed.' >&2
    fi
  fi
  return "$result"
}
trap rollback_web EXIT
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
if docker container inspect oldora-web >/dev/null 2>&1; then
  owner="$(docker inspect --format '{{index .Config.Labels "io.oldora.cloud"}}' oldora-web)"
  # Recognize the original onboarding container only by its complete known setup.
  if [ "$owner" != '1' ] && [ -n "$owner" ] ||
       [ "$(docker inspect --format '{{.Config.Image}}' oldora-web)" != 'oldora-php-dev' ] ||
       [ "$(docker inspect --format '{{.Config.WorkingDir}}' oldora-web)" != '/workspace/oldora' ] ||
       [ "$(docker inspect --format '{{json .Config.Cmd}}' oldora-web)" != '["php","-S","127.0.0.1:8000","-t","/workspace/oldora"]' ] ||
       [ "$(docker inspect --format '{{.HostConfig.NetworkMode}}' oldora-web)" != 'host' ] ||
       [ "$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/workspace"}}{{.Source}}{{end}}{{end}}' oldora-web)" != '/workspace' ]; then
      echo 'The oldora-web container is not owned by this setup; inspect it before changing it.' >&2
      exit 1
  fi
  previous_fingerprint="$(docker inspect --format '{{index .Config.Labels "io.oldora.runtime"}}' oldora-web)"
  if [ "$previous_fingerprint" != "$runtime_fingerprint" ]; then
    candidate_backup="oldora-web-before-$$-$RANDOM"
    docker stop --time 20 oldora-web >/dev/null
    web_paused=true
    docker rename oldora-web "$candidate_backup"
    web_backup="$candidate_backup"
    web_paused=false
  else
    docker start oldora-web >/dev/null
  fi
fi

video_fingerprint="$(oldora_runtime_fingerprint VIDEO_LLM_KEY VIDEO_LLM_MODEL VIDEO_PEXELS_KEY MONEYPRINTER_ROOT MONEYPRINTER_API_KEY)"
video_probe() { python3 /workspace/oldora/bin/video-service-probe.py "$1"; }
video_config_changed=false
video_started=false
if ! video_probe listening; then
  video_config_changed=true
elif [ "$(cat "$setup/video-runtime.sha256" 2>/dev/null || true)" != "$video_fingerprint" ]; then
  video_config_changed=true
fi
video_process_owned() {
  python3 - "$1" <<'PY'
import os
import pathlib
import sys

pid = sys.argv[1]
if not pid.isdigit(): sys.exit(1)
try:
    process = pathlib.Path('/proc') / pid
    args = (process / 'cmdline').read_bytes().split(b'\0')
    owned = process.stat().st_uid == os.geteuid() and len(args) > 1 and args[1] == b'/workspace/oldora/bin/start-video-service.py'
    sys.exit(0 if owned else 1)
except OSError:
    sys.exit(1)
PY
}
video_tasks_idle() { video_probe idle; }

video_assets_collected() {
  "$setup/bin/php" -r 'require "connection.php"; $r=$con->query("SELECT COUNT(*) AS n FROM content_items WHERE provider = '\''moneyprinterturbo'\'' AND status IN ('\''starting'\'', '\''queued'\'', '\''processing'\'', '\''in_progress'\'') AND provider_job_id IS NOT NULL"); exit((int)$r->fetch_assoc()["n"] === 0 ? 0 : 1);'
}
if [ "$video_config_changed" = true ]; then
  video_pid="$(cat "$setup/video-service.pid" 2>/dev/null || true)"
  if video_process_owned "$video_pid"; then
    if video_tasks_idle && video_assets_collected; then
      # Stop our request/automation producers, then recheck before ending the idle API.
      web_paused=false
      if docker container inspect oldora-web >/dev/null 2>&1; then
        docker stop --time 20 oldora-web >/dev/null
        web_paused=true
      fi
      if video_tasks_idle && video_assets_collected && video_process_owned "$video_pid"; then
        kill -TERM "$video_pid"
        for attempt in $(seq 1 30); do
          if ! video_process_owned "$video_pid"; then break; fi
          sleep 1
        done
        if video_process_owned "$video_pid"; then
          echo 'Video service shutdown is still pending; configuration was not marked applied.' >&2
          exit 1
        fi
        video_started=true
      else
        echo 'Video service configuration change is deferred until queued videos and downloads finish.' >&2
      fi
      if [ "$web_paused" = true ]; then
        docker start oldora-web >/dev/null
        web_paused=false
      fi
    else
      if ! video_probe ready; then
        echo 'Video authentication could not verify idle tasks; the current service and web bindings are preserved. Verify the previous service key before retrying the configuration change.' >&2
        exit 1
      fi
      echo 'Video service configuration change is deferred until queued videos and downloads finish.' >&2
    fi
  elif video_probe listening; then
    echo 'A video API is running without our verified launcher PID; configuration requires its owner to restart it.' >&2
    exit 1
  else
    video_started=true
  fi
  if [ "$video_started" = true ]; then
    nohup "$setup/MoneyPrinterTurbo/.venv/bin/python" /workspace/oldora/bin/start-video-service.py > "$setup/video-service.log" 2>&1 &
    echo "$!" > "$setup/video-service.pid"
  fi
fi
if ! docker container inspect oldora-web >/dev/null 2>&1; then
  docker run -d --name oldora-web --network host --init "${oldora_docker_env_args[@]}" \
    --label io.oldora.cloud=1 --label "io.oldora.runtime=$runtime_fingerprint" --env PHP_CLI_SERVER_WORKERS=4 \
    -u "$(id -u):$(id -g)" -v /workspace:/workspace -w /workspace/oldora oldora-php-dev \
    php -S 127.0.0.1:8000 -t /workspace/oldora
fi
for attempt in $(seq 1 30); do
  if video_probe ready; then break; fi
  sleep 1
done
video_probe ready
# The PHP adapter must authenticate with the requested binding before replacing the old web service.
"$setup/bin/php" -r 'require "includes/moneyprinter.php"; oldora_moneyprinter_request("/api/v1/tasks");'
if [ "$video_started" = true ]; then
  (umask 077; printf '%s\n' "$video_fingerprint" > "$setup/video-runtime.sha256")
fi
for attempt in $(seq 1 30); do
  if curl -fsS http://127.0.0.1:8000/index.php -o "$setup/landing-smoke.html"; then break; fi
  sleep 1
done
curl -fsS http://127.0.0.1:8000/index.php -o "$setup/landing-smoke.html"
rg -q 'AI Social Growth Automation' "$setup/landing-smoke.html"
mkdir -p /workspace/storage/logs
docker exec -d oldora-web sh -c 'exec php /workspace/oldora/bin/run-workers.php >> /workspace/storage/logs/workers.log 2>&1'
if [ -n "$web_backup" ]; then
  docker rm "$web_backup" >/dev/null
  web_backup=''
fi
echo 'PHP, MariaDB, and video API startup checks passed; payment and content workers launched.'
