<?php

namespace App\Livewire;

use Livewire\Component;

class SearchLog extends Component
{
    public array $messages = [];

    public bool $complete = false;

    public function mount(array $messages, bool $complete = false): void
    {
        $this->messages = $messages;
        $this->complete = $complete;

        // Never have empty messages.
        if (count($this->messages) === 0) {
            $this->messages[] = [
                'message' => __('Waiting for results...'),
                'data' => ['icon' => 'ellipsis-horizontal'],
                'timestamp' => now()->toDateTimeString(),
            ];
        }
    }

    public function render()
    {
        return view('components.livewire.search-log');
    }
}
