# Rencoderr AI Guidelines

These project rules apply to all repository work and take precedence over conflicting generated guidance.

## Commit Authorship

- Never add an AI model, assistant, bot, or tool as a commit author or co-author.
- Never add AI attribution to commit messages, including `Co-authored-by` trailers for AI models or tools.
- Preserve the configured human Git identity. Do not invent or replace it with an AI identity.

## Required Checks Before Committing

Before every commit, run all of the following against the final working tree:

```bash
composer test
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
```

- The complete test suite, PHPStan analysis, and Pint checks must all pass before creating any commit. Filtered tests or checks limited to changed files do not satisfy this requirement.
- Fix failures and rerun the required checks after making changes. Do not skip checks or suppress failures solely to permit a commit.
- If a required tool or its project configuration is missing, or a check cannot run, report the blocker and do not commit.

## English Only

- Write all commit messages, code, identifiers, comments, and documentation in English.

## Testing

- Use Pest for all tests, with `it()` or `test()` and Pest expectations.
- Create tests with `php artisan make:test --pest --no-interaction {name}`. Add `--unit` only for tests that do not need Laravel.
- Run the suite with `composer test`, or run targeted tests with `php artisan test --compact` or `vendor/bin/pest`.
- Keep the shared Laravel test case binding in `tests/Pest.php` scoped to feature tests.
- Real-browser testing and manual browser verification must be performed by the user, never by agents. Agents verify changes with Pest, PHPStan, Pint, and frontend builds.

## Code Organization

- Group code by domain under `app/`. Each domain folder holds its actions, enums, events, listeners, exceptions and value objects:
  - `Library`: libraries, their folders, files on disk and scanning.
  - `Probe`: ffprobe metadata for media files.
  - `Encoding`: available encoders, hardware acceleration and the acceleration preference.
  - `Ffmpeg`: running FFmpeg and the hardware backend vocabulary shared by the other domains.
  - `Tasks`: the status and queue names shared by tracked background work.
  - `Workers`: the Horizon worker limit.
  - `Users`: account management.
- Keep models in `app/Models`, jobs in `app/Jobs`, and Livewire form objects in `app/Livewire/Forms`. Write a query where it is used; turn it into a `#[Scope]` or a model method only when several places need it.
- Track background work with `TaskStatus` and token-guarded state changes, run it on a `WorkQueue`, and requeue interrupted work in the `tasks:recover` command.
- Let domains react to each other through events, such as `LibraryPathScanned`, instead of calling each other directly.
- Write enum case names in UPPERCASE.

## Frontend

- Build interactive pages with Livewire 4 and use TallStackUI 4 Blade components for the interface.
- Create class-based Livewire components with `php artisan make:livewire {name} --class --no-interaction`.
- Use `Route::livewire()` with named routes for full-page components and share the layout in `resources/views/layouts/app.blade.php`.
- Load the TallStackUI script before Vite assets and use the Alpine installation bundled with Livewire.
- Keep TallStackUI's Tailwind sources and styles in `resources/css/app.css`.

## Laravel Boost

- Use Laravel Boost when applicable for version-specific documentation, project inspection, and Laravel development guidance.
- Prefer applicable Boost tools and skills when available. When they are unavailable, inspect the installed packages and use Artisan commands directly.
- Keep these project rules in `.ai/guidelines/project.md` so Boost can include them in generated agent instructions.
- After changing these rules, regenerate the agent instructions with `php artisan boost:update --no-discover --ignore-skills --no-interaction`.
