<?php

namespace App\Livewire\Forms;

use App\Library\Actions\SaveLibrary;
use App\Models\Library;
use App\Models\LibraryPath;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

class LibraryForm extends Form
{
    private const array MINUTES_PER_UNIT = ['days' => 1440, 'hours' => 60, 'minutes' => 1];

    private const int MAX_INTERVAL_MINUTES = 2147483647;

    public string $name = '';

    public bool $automaticScanning = true;

    public string $interval = '15';

    public string $intervalUnit = 'minutes';

    public string $exclusions = '';

    /** @var list<string> */
    public array $paths = [];

    public string $newPath = '';

    /**
     * Show the interval in the largest unit that divides it evenly.
     */
    public function fillFrom(Library $library): void
    {
        $minutes = $library->scan_interval_minutes;
        $this->name = $library->name;
        $this->automaticScanning = $library->automatic_scanning;
        $this->intervalUnit = (string) collect(self::MINUTES_PER_UNIT)->search(fn (int $unitMinutes): bool => $minutes % $unitMinutes === 0);
        $this->interval = (string) intdiv($minutes, self::MINUTES_PER_UNIT[$this->intervalUnit]);
        $this->exclusions = implode("\n", $library->exclusion_patterns);
        $this->paths = array_values($library->paths->map(fn (LibraryPath $path): string => $path->path)->all());
    }

    public function addPath(): void
    {
        $this->validate(['newPath' => ['required', 'string', 'max:4096']]);
        $this->paths[] = trim($this->newPath);
        $this->newPath = '';
    }

    public function removePath(int $index): void
    {
        $this->paths = array_values(array_filter($this->paths, fn (int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));
    }

    public function save(?int $libraryId, SaveLibrary $saveLibrary): Library
    {
        if (trim($this->newPath) !== '') {
            $this->addPath();
        }

        $this->name = trim($this->name);
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'automaticScanning' => ['boolean'],
            'interval' => ['required', 'integer', 'min:1', 'max:'.self::MAX_INTERVAL_MINUTES, function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->intervalMinutes() > self::MAX_INTERVAL_MINUTES) {
                    $fail('The scan interval is too large.');
                }
            }],
            'intervalUnit' => ['required', Rule::in(array_keys(self::MINUTES_PER_UNIT))],
            'exclusions' => ['string', 'max:16384'],
            'paths' => ['required', 'array', 'min:1'],
            'paths.*' => ['required', 'string', 'max:4096'],
        ]);

        try {
            return $saveLibrary->handle($libraryId, [
                'name' => $this->name,
                'automatic_scanning' => $this->automaticScanning,
                'scan_interval_minutes' => $this->intervalMinutes(),
                'exclusion_patterns' => $this->exclusionPatterns(),
            ], $this->paths);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [$this->getPropertyName().'.'.$field => $messages])->all());
        }
    }

    private function intervalMinutes(): int
    {
        return (int) $this->interval * (self::MINUTES_PER_UNIT[$this->intervalUnit] ?? 1);
    }

    /** @return list<string> */
    private function exclusionPatterns(): array
    {
        return array_values(array_unique(array_filter(
            array_map(trim(...), explode("\n", $this->exclusions)),
            fn (string $pattern): bool => $pattern !== '',
        )));
    }
}
