#!/usr/bin/env bash
set -euo pipefail
cd /workspace/oldora
setup=/workspace/.setup
mkdir -p "$setup/php" "$setup/bin" "$setup/docker"
export DOCKER_CONFIG="$setup/docker"
cat > "$setup/php/Dockerfile" <<'EOF'
FROM php:8.3-cli@sha256:005cf2ef7f7aa8c17cefa1b732e7f027065c9589d69b4abdd644a02782fbc94f
RUN docker-php-ext-install mysqli
EOF
docker build -t oldora-php-dev "$setup/php"
docker pull mariadb:11.4@sha256:1292844148b311e4ed4300022a996d39083f415a963e970cf47cad1b3b18e3a6
revision=68eb5a68b93cfe338198b3dfb151f6d5ec2fe4e5
if [ ! -d "$setup/MoneyPrinterTurbo" ]; then
  git clone https://github.com/harry0703/MoneyPrinterTurbo.git "$setup/MoneyPrinterTurbo"
  git -C "$setup/MoneyPrinterTurbo" checkout --detach "$revision"
fi
[ "$(git -C "$setup/MoneyPrinterTurbo" rev-parse HEAD)" = "$revision" ] || { echo 'MoneyPrinterTurbo revision differs; inspect before updating.' >&2; exit 1; }
export UV_CACHE_DIR="$setup/uv-cache" UV_PYTHON_INSTALL_DIR="$setup/python"
(cd "$setup/MoneyPrinterTurbo" && uv sync --frozen --no-dev --python "$(command -v python3)")
if [ ! -e "$setup/MoneyPrinterTurbo/config.toml" ]; then
  cp "$setup/MoneyPrinterTurbo/config.example.toml" "$setup/MoneyPrinterTurbo/config.toml"
fi
cat > "$setup/bin/php" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
source /workspace/oldora/bin/cloud-runtime-env.sh
exec docker run --rm --network host "${oldora_docker_env_args[@]}" -u "$(id -u):$(id -g)" -v /workspace:/workspace -w "$PWD" oldora-php-dev php "$@"
EOF
chmod +x "$setup/bin/php"
# Arabic captions must use a font inside MoneyPrinterTurbo's allowed font directory.
font=/usr/share/fonts/truetype/noto/NotoSansArabic-Bold.ttf
if [ -f "$font" ] && [ ! -f "$setup/MoneyPrinterTurbo/resource/fonts/NotoSansArabic-Bold.ttf" ]; then
  cp "$font" "$setup/MoneyPrinterTurbo/resource/fonts/NotoSansArabic-Bold.ttf"
fi
# Persist image artifacts as files; live Docker processes do not survive snapshots.
php_image="$(docker image inspect --format '{{.Id}}' oldora-php-dev)"
if [ ! -f "$setup/oldora-php-dev.tar" ] || [ "$(cat "$setup/php-image.id" 2>/dev/null || true)" != "$php_image" ]; then
  docker save -o "$setup/oldora-php-dev.tar.tmp" oldora-php-dev
  mv "$setup/oldora-php-dev.tar.tmp" "$setup/oldora-php-dev.tar"
  printf '%s\n' "$php_image" > "$setup/php-image.id"
fi
if [ ! -f "$setup/mariadb-dev.tar" ]; then docker save -o "$setup/mariadb-dev.tar" mariadb:11.4@sha256:1292844148b311e4ed4300022a996d39083f415a963e970cf47cad1b3b18e3a6; fi
mkdir -p uploads/generated
"$setup/bin/php" -r 'foreach (["mysqli","curl","mbstring","openssl"] as $e) { if (!extension_loaded($e)) throw new RuntimeException("Missing ".$e); } echo "PHP extensions ready\n";'
