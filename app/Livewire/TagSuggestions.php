<?php

namespace App\Livewire;

use App\Domain\Health\LibraryReports;
use App\Domain\Health\Suggestions;
use Livewire\Component;

/**
 * The review list of the `suggestions` library health report: each tag or cover suggestion with Accept (writes the tag into
 * the file, or fetches the cover) and Dismiss. Admin only: it sits on /settings/library-health/suggestions behind the login,
 * and the actions check it again. Domain: App\Domain\Health\Suggestions; docs/api/suggestions.md.
 */
class TagSuggestions extends Component
{
    public int $limit = 100;

    public ?string $notice = null;

    public ?string $error = null;

    public function accept(string $id): void
    {
        $this->act($id, function () use ($id) {
            $result = Suggestions::accept($id);
            $this->notice = $result['status'] === 'queued' ? 'The cover is being fetched.' : 'Written into the file.';
        });
    }

    public function dismiss(string $id): void
    {
        $this->act($id, function () use ($id) {
            Suggestions::dismiss($id);
            $this->notice = 'Dismissed.';
        });
    }

    public function more(): void
    {
        $this->limit += 100;
    }

    private function act(string $id, \Closure $action): void
    {
        abort_unless(auth()->check(), 403);
        $this->notice = $this->error = null;

        try {
            $action();
            LibraryReports::forget();
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $all = Suggestions::all();

        return view('livewire.tag-suggestions', ['rows' => array_slice($all, 0, $this->limit), 'total' => count($all)]);
    }
}
