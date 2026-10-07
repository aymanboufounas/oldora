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

Run `bash bin/start-cloud.sh` to start the local database, initialize `oldora_dev`, and start both APIs with readiness checks. It also starts `bin/run-workers.php`, which runs payment reconciliation and content processing automatically, then repeats after a 60-second pause. The supervisor holds a file lock; the content worker also holds a database lock to prevent overlapping publishing. Worker output is recorded in `/workspace/storage/logs/workers.log`. The script preserves an existing parent `.env`. Add `/workspace/.setup/bin` to `PATH` for the Docker-backed `php` command.

Start MoneyPrinterTurbo separately from its virtual environment:

```sh
/workspace/.setup/MoneyPrinterTurbo/.venv/bin/python /workspace/oldora/bin/start-video-service.py
```

The launcher takes `VIDEO_LLM_KEY` (OpenAI) and `VIDEO_PEXELS_KEY` from the process environment and keeps them in memory. Enter these securely in environment settings. It defaults to `gpt-4o-mini`; `VIDEO_LLM_MODEL` overrides the model. Outbound services include `api.openai.com`, `api.pexels.com`, `videos.pexels.com`, and `speech.platform.bing.com` for Edge TTS. Local footage with a supplied script and no-voice mode can validate rendering without these credentials. The private API should return HTTP 200 for `/api/v1/tasks`; successful startup alone does not verify external provider access.

For the local launcher, `MONEYPRINTER_API_KEY` configures service authentication in memory and the PHP client uses the same `x-api-key` header. Startup probes also authenticate. Changing the key requires verifying that the current service is idle using its current key; if that cannot be verified, startup preserves the existing service and reports the deferred change.

Run PHP with the checkout mounted at its original path and a writable `uploads/generated` directory:

```sh
/workspace/.setup/bin/php -S 127.0.0.1:8000 -t /workspace/oldora
```

Use local requests for validation. PHP's development server ignores Apache `.htaccess`; use Apache and HTTPS for deployment. Oldora sets secure session cookies, so browser authentication requires HTTPS. Set `APP_URL` to the HTTPS public origin before deployment. Keep MoneyPrinterTurbo private; if it runs remotely, use HTTPS and configure matching `MONEYPRINTER_API_KEY` and MoneyPrinterTurbo `[app].api_key` through your secret manager.

## Video lifecycle

`VIDEO_PROVIDER` accepts `openai` (default) or `moneyprinterturbo`. Configure `MONEYPRINTER_API_URL` as the service origin, without `/api/v1`. Optional variables: `MONEYPRINTER_VIDEO_LANGUAGE` (en), `MONEYPRINTER_VOICE` (en-US-AriaNeural), `MONEYPRINTER_VIDEO_SOURCE` (pexels), and `MONEYPRINTER_FONT` (MicrosoftYaHeiBold.ttc).

The cloud supervisor runs `php cron_content.php` to poll queued videos and save successful MP4s under `uploads/generated`. This worker also processes watchers and publishes already-authorized scheduled posts; enabling the provider does not itself authorize publishing. Failed video tasks refund reserved credits once using a transaction. Failed generation also stops the associated waiting publish jobs. Run `php bin/run-workers.php --once` for a single payment/content pass, or schedule the individual cron scripts in another deployment. HTTP cron access requires `CRON_SECRET`; prefer CLI execution for the local supervisor.

An interrupted generation left in `starting` for over 30 minutes without a saved provider receipt is failed and refunded once, and its waiting publishing jobs stop. Late provider responses cannot resurrect that refunded record. Healthy saved video jobs and ready images remain intact when a later balance refresh or publishing-queue update fails; the worker continues from the persisted state.

Content Studio restores your creative brief and pending video ID in the current browser tab. Its status endpoint reads persisted worker results; keeping the browser open is not required for rendering or publishing. Account credit displays refresh from the server, including confirmed payment additions and generation refunds.

## Creative quality controls

Studio accepts English, Modern Standard Arabic, Moroccan Darija, French and Spanish, together with a tone, intended audience, visual direction and optional accent color. These values are validated on the server and saved with each creation. The prompt templates request a clear hook, coherent composition, readable safe margins and concrete details without fabricated offers or statistics. Visual direction is creative guidance; stock footage selection does not guarantee an exact art style.

Images use OpenAI with square, portrait 2:3 or landscape 3:2 output. Square is the default. Images are requested as JPEG and verified before saving as `.jpg` for Instagram compatibility. Square and landscape can publish to Instagram; portrait 2:3 remains available for download and is rejected for Instagram publishing before credits are reserved. `OPENAI_IMAGE_MODEL` and `OPENAI_IMAGE_QUALITY` remain deployment settings.

MoneyPrinterTurbo supports curated feminine/masculine narration choices matched to the content language, natural/relaxed/energetic pacing, optional quiet background music, and clean/bold/no subtitles. Script instructions aim for concise spoken narration of roughly 90–120 words; duration depends on the actual script and speaking pace. Stock clips are selected in narration order. OpenAI video generation uses the creative brief but does not expose these MoneyPrinter-specific voice/music/subtitle controls.

Arabic and Darija narration select matching Edge TTS voices. Arabic subtitles use `MONEYPRINTER_ARABIC_FONT` (default `NotoSansArabic-Bold.ttf`). Setup copies this font into the private service's `resource/fonts` directory from the system Noto installation; MoneyPrinterTurbo requires the font to be inside that directory. Live pronunciation, subtitle shaping and music output must be checked with the configured providers; offline contract checks validate option mapping only.

## Publishing progress

Publishing remains an explicit choice of linked accounts and consent. Account ownership, supported media types, privacy choices, captions and future schedules are checked before credit reservation. TikTok rejects unavailable requested privacy rather than silently changing it.

YouTube visibility is independent of TikTok privacy. Studio defaults to private and offers private, unlisted or public visibility when a YouTube channel is selected. The Home scheduling form preserves its existing public-upload intent by explicitly requesting public YouTube visibility. Each platform's selected setting is stored with its publishing job.

Queue states distinguish `waiting_media`, `pending`, `publishing`, `submitted` (accepted/processing), and `published` (confirmed), along with `paused`, `cancelled`, `failed`, and `needs_review`. An accepted remote request is not counted as published until its platform confirms completion. Processing receipts are retained for status checks instead of uploading another copy. Instagram video containers continue processing across worker passes before final publication; TikTok publish receipts are polled until completion. Interrupted or uncertain writes require checking the connected account before an explicit retry. Safe pre-publication failures retry with a bounded delay; generation and publishing failures remain separate.

Automation exposes worker health, attempts and errors, plus owner-only pause, resume, reschedule, retry, publish-now and cancellation controls. Public HTTPS media URLs and connected platform permissions are required for Instagram/TikTok. Local startup and mock receipts do not establish live social publishing readiness.

## Checks

Run `python3 tests/moneyprinter_fixture.py` in one terminal and `php tests/moneyprinter.php` in another. These checks use a local contract fixture and test provider selection, status mapping, artifact saving, invalid upstream states, and download URL restrictions. The fixture artifact is synthetic; it does not demonstrate real rendering. For real rendering, submit supplied local footage and script to the service and verify a completed, playable MP4. Live topic-to-video generation additionally requires working LLM, Pexels, and TTS access.

`php tests/content-options.php` checks prompt, format, language, voice and subtitle mappings without provider calls. `php tests/content-options-endpoint.php` uses a disposable development database and provider stubs to check validation, ownership, credit reservation/refund, per-platform visibility, publishing queues and failure after a durable media save. `php tests/automation.php`, `php tests/publisher-status.php` and `php tests/publisher-uncertainty.php` cover worker transitions, retained receipts, asynchronous platform states and uncertain publishing outcomes. `php tests/youtube-watcher.php` checks watcher selection, source-event handling and retry behavior. Live generation and posting still require separate provider checks.

The default MoneyPrinterTurbo queue and task state are in memory. Finish queued work before restarting the service; use its supported private Redis configuration when durable task state is required. Browser HTTPS, external generation credentials, email verification, social OAuth, and payment integrations require deployment configuration beyond the local smoke checks.
