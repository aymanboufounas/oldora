#!/usr/bin/env bash
# Shared names-only allowlist: never forward unrelated host credentials.
oldora_runtime_names=(
  APP_URL DB_HOST DB_USER DB_PASS DB_NAME CRON_SECRET
  CRYPTOMUS_MERCHANT_ID CRYPTOMUS_API_KEY AUTOMATION_STALE_SECONDS
  OPENAI_API_KEY OPENAI_TEXT_MODEL OPENAI_IMAGE_MODEL OPENAI_IMAGE_SIZE OPENAI_IMAGE_QUALITY
  OPENAI_VIDEO_MODEL OPENAI_VIDEO_SIZE OPENAI_VIDEO_SECONDS IMAGE_CREDIT_COST VIDEO_CREDIT_COST
  VIDEO_PROVIDER VIDEO_LLM_KEY VIDEO_LLM_MODEL VIDEO_PEXELS_KEY
  MONEYPRINTER_API_URL MONEYPRINTER_API_KEY MONEYPRINTER_VIDEO_LANGUAGE MONEYPRINTER_VOICE
  MONEYPRINTER_VIDEO_SOURCE MONEYPRINTER_FONT MONEYPRINTER_ARABIC_FONT MONEYPRINTER_ROOT
  FB_APP_ID FB_APP_SECRET FB_REDIRECT_URI META_GRAPH_VERSION
  YOUTUBE_API_KEY YOUTUBE_CLIENT_ID YOUTUBE_CLIENT_SECRET YOUTUBE_REDIRECT_URI
  YOUTUBE_CATEGORY_ID YOUTUBE_UPLOAD_PRIVACY
  TIKTOK_CLIENT_KEY TIKTOK_CLIENT_SECRET TIKTOK_REDIRECT_URI TIKTOK_DEFAULT_PRIVACY
  SMTP_HOST SMTP_PORT SMTP_USER SMTP_PASS
)
oldora_docker_env_args=()
for oldora_runtime_name in "${oldora_runtime_names[@]}"; do
  if [[ -v "$oldora_runtime_name" ]]; then
    oldora_docker_env_args+=(--env "$oldora_runtime_name")
  fi
done

oldora_runtime_fingerprint() {
  local names=("$@")
  if [ "${#names[@]}" -eq 0 ]; then names=("${oldora_runtime_names[@]}"); fi
  python3 - "${names[@]}" <<'PY'
import hashlib
import os
import sys

digest = hashlib.sha256()
for name in sys.argv[1:]:
    value = os.environ.get(name)
    digest.update(name.encode() + b'\0')
    digest.update(b'\0' if value is None else b'\1' + value.encode(errors='surrogateescape'))
    digest.update(b'\0')
print(digest.hexdigest())
PY
}
