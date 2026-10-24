# Rencoderr

A self-hosted media library manager focused on saving storage while preserving visual quality, built with Laravel, Livewire, and FFmpeg.

Rencoderr's goal is to scan your media libraries and re-encode files using more efficient codecs, such as converting H.264 to AV1, to reduce file sizes without a perceptible loss of quality.

The project is in active development. Library management, scheduled scans, media inventory, hardware encoder checks, and media settings with library overrides are available. Re-encoding is planned; the transcoding queue is currently a placeholder.

## Docker Compose

Requires Docker with the Compose plugin. The current container targets Linux AMD64 and includes the web server, background workers, scheduler, FFmpeg, SQLite, and Valkey.

Create a directory for the application:

```bash
mkdir rencoderr
cd rencoderr
```

Save the following as `compose.yaml`. It uses the image published to GitHub Container Registry from [Lukasss93/rencoderr](https://github.com/Lukasss93/rencoderr):

```yaml
services:
  rencoderr:
    container_name: rencoderr
    image: ghcr.io/lukasss93/rencoderr:latest
    platform: linux/amd64
    restart: unless-stopped
    stop_grace_period: 30s
    ports:
      - "8000:8000"
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: Europe/Rome
      APP_URL: http://localhost:8000
      AUTH_ENABLED: "true"
    volumes:
      - ./config-data:/config
      - ./media:/media
```

Set `PUID` and `PGID` to the host user and group that should access your media (`id -u` and `id -g`). Replace `./media` with your media directory, and set `APP_URL` to the address you will use to access the application.

Start the application:

```bash
docker compose pull
docker compose up -d
docker compose logs -f rencoderr
```

Startup creates the database, runs migrations, and generates an application key that is persisted under `/config`.

Once the container is healthy, create your first account using the interactive prompts:

```bash
docker compose exec --user rencoderr rencoderr php artisan user:create
```

Open `http://localhost:8000`, sign in, and add a library under **Settings → Libraries**. Use container paths such as `/media/movies`, rather than host paths.

### Persistent data

- `/config` stores the database, application key, and runtime data. Keep it on local storage with reliable filesystem locking and back it up when replacing or upgrading the container.
- `/media` exposes your media files to the application. You can mount additional directories if needed.

A `.env` file is not required for this example; configure the environment variables and volume paths directly in `compose.yaml`.

### Media settings

Open **Settings → Media** to save global media preferences. In **Settings → Libraries**, choose **Media settings** on a library to override individual fields. New and existing libraries inherit every global setting until an override is saved.

Global preferences and library overrides are stored as individual rows in the `settings` table, with keys such as `media.video_codec`, `media.hdr_mode`, and `media.audio_languages`. Global rows have no owner; library overrides use the polymorphic `settingable` relation (`settingable_type = library`, `settingable_id = library ID`). Access them through `$library->settings()` and their owner through `$setting->settingable`. Each row contains one setting value; list preferences retain their arrays. Inherited fields have no library row, and resetting an override deletes its row. Deleting a library also removes its settings.

The **H.265 — High quality** and **AV1 — High quality** presets set the video codec, high quality, and balanced encoding speed. **Apply preset** changes the form; **Save media settings** persists it. Presets leave HDR, audio, subtitles, and safeguards unchanged. On a library page, applying a preset overrides exactly those three video fields. You can edit them afterward.

| Preference | Default | Available choices |
| --- | --- | --- |
| Video codec | H.265 | H.265, AV1 |
| Video quality | High | High, balanced, smaller files |
| Encoding speed | Balanced | Fast, balanced, slow |
| HDR handling | Preserve | Preserve, convert to SDR, skip HDR files |
| Audio codec / channels | Copy original / preserve | Copy, AAC, Opus / preserve, stereo |
| Converted audio bitrate | 64 kbps per output channel | 32–256 kbps per channel |
| Lossless audio | Preserve | Preserve or allow conversion |
| Audio / subtitle languages | All | Optional three-letter language tags |
| Subtitles / forced subtitles | Retain / preserve | Enable or disable independently |
| Skip files using these video codecs | H.265, AV1 | Any selection of H.264, H.265, AV1 |
| Smaller output / minimum savings | Required / 5% | Enable or disable / 0–99% |
| Video quality verification / minimum VMAF | Enabled / 95 | Enable or disable / 0–100 |

HDR means high dynamic range; SDR means standard dynamic range. VMAF (Video Multi-Method Assessment Fusion) measures perceptual similarity, not mathematical losslessness. Quality and speed are preferences; their encoder-specific implementation belongs to the transcoding workflow.

Changes to global settings apply to fields a library inherits. An explicit override stays independent even if its value equals the global value. Disable **Override global setting** for one field, or choose **Use global settings for all**, then save to restore inheritance. Empty language lists explicitly mean all languages; empty skip-codec lists mean no codec-based skipping. Language tags are normalized to lowercase and deduplicated; examples include `eng`, `ita`, and `und` for unknown languages.

The preferences define these processing policies:

- Resolution and frame rate remain unchanged. HDR preservation must skip unsupported files instead of silently stripping HDR.
- Copied audio retains its codec, bitrate, and channels. Preserving lossless audio takes precedence over conversion and channel settings for those tracks. A language filter that would remove every existing audio track must skip the file.
- Forced subtitles are kept regardless of the language filter when subtitle retention and forced-subtitle preservation are both enabled. Disabling subtitle retention removes all subtitles.
- Skip-codec selections skip the whole file. To convert existing H.265 files to AV1, remove H.265 from the skip list; applying the AV1 preset does not change that list.
- Minimum savings applies only when smaller output is required; 0% still requires strictly smaller output. Minimum VMAF applies only when quality verification is enabled. Values remain saved while their related option is disabled.

These settings currently save configuration only. They do not start transcoding, perform quality checks, or replace files. Hardware acceleration and worker limits remain global, separate settings.

### Queue workers

Open **Settings → Workers** to set the maximum number of background workers. Scans and media probes share this pool, with scans taking priority. Horizon keeps at least one worker and scales up as queued work increases.

Saving applies the limit automatically while Horizon runs; no Horizon or container restart is needed. Worker counts adjust gradually. When lowering the limit, retiring workers finish their current jobs within the existing timeouts before exiting. Saving a limit does not resume paused workers.

The page shows the saved maximum, the limit reported by Horizon, and the current worker count. Workers finishing their last job are excluded from that count. If Horizon is offline, the saved limit applies when it starts; if status cannot be read, the page shows that it is unavailable rather than claiming the limit was applied.

The saved maximum persists in `/config` and takes precedence over `HORIZON_WORKERS`. Until you save a maximum, `HORIZON_WORKERS` supplies the default, which is `1`.

### Hardware acceleration

For Intel or AMD hardware exposed through `/dev/dri`, add this under the `rencoderr` service:

```yaml
    devices:
      - /dev/dri:/dev/dri
```

For NVIDIA hardware, install the NVIDIA Container Toolkit on the host, add `NVIDIA_DRIVER_CAPABILITIES: compute,video,utility` to the service's `environment`, and add:

```yaml
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              count: all
              capabilities: [gpu]
```

Apply the changes with `docker compose up -d`. Open **Settings → Status** to inspect detected encoders, test them, and choose your hardware acceleration preference. Availability depends on the host GPU, drivers, and FFmpeg support. Detection results are kept until you select **Refresh detection** or restart the container.

## Development checks

Clone the source repository for development:

```bash
git clone https://github.com/Lukasss93/rencoderr.git
cd rencoderr
```

With the project dependencies installed, run:

```bash
composer test
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
```
