# MoneyPrinterTurbo video backend

Content Studio and automatic YouTube Shorts can use a private MoneyPrinterTurbo API. Existing OpenAI videos continue to poll and download through OpenAI because each job stores its provider. Images still use OpenAI. The existing credit reservation, failed-job refund, scheduling, and explicit publishing consent flow remain in place.

## Cloud development

Use the existing `/workspace/oldora` checkout. Run `bash bin/setup-cloud.sh` to build PHP 8.3 with mysqli, install pinned MoneyPrinterTurbo dependencies with its frozen lockfile, and retain Docker images for future starts. Requires Docker, Python 3.11+, uv, Git, and FFmpeg (imageio-ffmpeg also supplies a verified package binary). MoneyPrinterTurbo is a dependency checkout at `/workspace/.setup/MoneyPrinterTurbo`, pinned to `68eb5a68b93cfe338198b3dfb151f6d5ec2fe4e5`.

Oldora requires `/workspace/.env` (one directory above the checkout). For the isolated local development database:

```ini
DB_HOST=127.0.0.1
DB_USER=root
DB_PASS=
DB_NAME=oldora_dev
APP_URL=http://127.0.0.1:8000
VIDEO_PROVIDER=moneyprinterturbo
MONEYPRINTER_API_URL=http://127.0.0.1:8080
```

Keep these local settings separate from production credentials. Do not overwrite an existing `.env`. Start MariaDB on loopback, then initialize a fresh development database with `/workspace/.setup/bin/php bin/setup-development-db.php`. The schema creates users and legacy support/auth tables plus the existing content, payment, account, and watcher schemas. It seeds no accounts and does not migrate existing production tables.

Run `bash bin/start-cloud.sh` to start the local database, initialize `oldora_dev`, and start both APIs with readiness checks. It preserves an existing parent `.env`. Add `/workspace/.setup/bin` to `PATH` for the Docker-backed `php` command.

Start MoneyPrinterTurbo separately from its virtual environment:

```sh
/workspace/.setup/MoneyPrinterTurbo/.venv/bin/python /workspace/oldora/bin/start-video-service.py
```

The launcher takes `VIDEO_LLM_KEY` (OpenAI) and `VIDEO_PEXELS_KEY` from the process environment and keeps them in memory. Enter these securely in environment settings. It defaults to `gpt-4o-mini`; `VIDEO_LLM_MODEL` overrides the model. Outbound services include `api.openai.com`, `api.pexels.com`, `videos.pexels.com`, and `speech.platform.bing.com` for Edge TTS. Local footage with a supplied script and no-voice mode can validate rendering without these credentials. The private API should return HTTP 200 for `/api/v1/tasks`; successful startup alone does not verify external provider access.

Run PHP with the checkout mounted at its original path and a writable `uploads/generated` directory:

```sh
/workspace/.setup/bin/php -S 127.0.0.1:8000 -t /workspace/oldora
```

Use local requests for validation. PHP's development server ignores Apache `.htaccess`; use Apache and HTTPS for deployment. Oldora sets secure session cookies, so browser authentication requires HTTPS. Set `APP_URL` to the HTTPS public origin before deployment. Keep MoneyPrinterTurbo private; if it runs remotely, use HTTPS and configure matching `MONEYPRINTER_API_KEY` and MoneyPrinterTurbo `[app].api_key` through your secret manager.

## Video lifecycle

`VIDEO_PROVIDER` accepts `openai` (default) or `moneyprinterturbo`. Configure `MONEYPRINTER_API_URL` as the service origin, without `/api/v1`. Optional variables: `MONEYPRINTER_VIDEO_LANGUAGE` (en), `MONEYPRINTER_VOICE` (en-US-AriaNeural), `MONEYPRINTER_VIDEO_SOURCE` (pexels), and `MONEYPRINTER_FONT` (MicrosoftYaHeiBold.ttc).

Run `php cron_content.php` at regular intervals to poll queued videos and save successful MP4s under `uploads/generated`. This worker also processes watchers and publishes already-authorized scheduled posts; enabling the provider does not itself authorize publishing. Failed video tasks refund credits using the existing transaction. Do not expose the worker without configuring `CRON_SECRET`.

## Checks

Run `python3 tests/moneyprinter_fixture.py` in one terminal and `php tests/moneyprinter.php` in another. These checks use a local contract fixture and test provider selection, status mapping, artifact saving, invalid upstream states, and download URL restrictions. The fixture artifact is synthetic; it does not demonstrate real rendering. For real rendering, submit supplied local footage and script to the service and verify a completed, playable MP4. Live topic-to-video generation additionally requires working LLM, Pexels, and TTS access.

The default MoneyPrinterTurbo queue and task state are in memory. Finish queued work before restarting the service; use its supported private Redis configuration when durable task state is required. Browser HTTPS, external generation credentials, email verification, social OAuth, and payment integrations require deployment configuration beyond the local smoke checks.
