<?php

namespace App\Livewire\Settings;

use App\Encoding\AccelerationPreference;
use App\Encoding\Data\Detection;
use App\Encoding\EncoderDetector;
use App\Encoding\EncoderTester;
use App\Encoding\Enums\EncoderType;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

/** @property-read Detection $detection */
#[Title('Status')]
class Status extends Component
{
    use Interactions;

    public string $preferredAcceleration = AccelerationPreference::AUTOMATIC;

    public function mount(AccelerationPreference $preference): void
    {
        $this->preferredAcceleration = $preference->saved();
    }

    #[Computed]
    public function detection(): Detection
    {
        return app(EncoderDetector::class)->current();
    }

    /**
     * Hardware may have changed since the page loaded, so the choice is checked against a fresh detection.
     */
    public function saveHardwareAcceleration(AccelerationPreference $preference, EncoderDetector $detector): void
    {
        $detection = $detector->refresh();
        unset($this->detection);
        $this->validate([
            'preferredAcceleration' => ['required', Rule::in($preference->choices($detection))],
        ], [
            'preferredAcceleration.in' => 'Choose an available hardware encoding backend, Automatic, or Software (CPU).',
            'preferredAcceleration.required' => 'Choose a hardware acceleration preference.',
        ]);
        $preference->save($this->preferredAcceleration);
        $this->toast()->success('Hardware acceleration preference saved.')->send();
    }

    public function refreshDetection(EncoderDetector $detector): void
    {
        $detector->refresh();
        unset($this->detection);
    }

    public function testEncoder(string $name, EncoderTester $tester): void
    {
        $encoder = $this->detection->encoder($name);
        abort_unless($encoder?->isTestable() === true, 404);

        $tester->test($encoder);
        unset($this->detection);
    }

    public function render(AccelerationPreference $preference): View
    {
        $detection = $this->detection;
        $availableBackends = $preference->availableBackends($detection);
        $savedAcceleration = $preference->saved();

        return view('livewire.settings.status', [
            'detection' => $detection,
            'availableBackends' => $availableBackends,
            'savedAcceleration' => $savedAcceleration,
            'effectiveAcceleration' => $preference->resolve($detection),
            'savedAccelerationUnavailable' => ! in_array($savedAcceleration, $preference->choices($detection), true),
            'groups' => $detection->encodersByCategory(),
            'videoCount' => $detection->availableCount(EncoderType::VIDEO),
            'audioCount' => $detection->availableCount(EncoderType::AUDIO),
        ])->layoutData(['settingsPage' => true]);
    }
}
