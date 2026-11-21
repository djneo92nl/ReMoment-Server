<?php

namespace App\Livewire;

use App\Domain\Clients\ClientAdmin;
use App\Domain\Library\LibrarySources;
use App\Domain\Node\NodeRoles;
use App\Models\Client;
use App\Models\Device;
use Livewire\Component;

class ClientManager extends Component
{
    public ?int $approvingId = null;

    public string $approveName = '';

    public string $approveType = 'single';

    /** @var array<string> Declared roles of a node that the admin switches on at approval. */
    public array $approveRoles = [];

    public ?int $editing = null;

    public string $editName = '';

    public string $editType = 'single';

    /** @var array<int> */
    public array $editDeviceIds = [];

    /** @var array<string> Enabled roles of a node (always within what it declared). */
    public array $editRoles = [];

    /** @var array<string> Sources this client doesn't see in the library (LibrarySources::all() keys). */
    public array $editHiddenSources = [];

    public function startApprove(int $clientId): void
    {
        $this->approvingId = $clientId;
        $this->approveName = '';
        $this->approveType = 'single';
        // A node's declared roles are pre-ticked; the admin unticks what should stay off.
        $this->approveRoles = Client::findOrFail($clientId)->roles ?? [];
    }

    public function approve(): void
    {
        $this->validate([
            'approveType' => ['required', 'in:single,multi'],
            'approveRoles' => ['array'],
            'approveRoles.*' => ['string', 'in:'.implode(',', NodeRoles::names())],
        ]);

        ClientAdmin::approve(Client::findOrFail($this->approvingId), [
            'type' => $this->approveType,
            'name' => $this->approveName,
            'roles' => $this->approveRoles,
        ]);

        $this->approvingId = null;
        session()->flash('success', 'Client approved.');
    }

    public function reject(int $clientId): void
    {
        ClientAdmin::delete(Client::findOrFail($clientId));
        session()->flash('success', 'Client registration rejected and removed.');
    }

    public function startEdit(int $clientId): void
    {
        $client = Client::with('devices')->findOrFail($clientId);
        $this->editing = $clientId;
        $this->editName = $client->name ?? '';
        $this->editType = $client->type;
        $this->editRoles = $client->activeRoles();
        $this->editDeviceIds = $client->devices->pluck('id')->map(fn ($id) => (int) $id)->toArray();
        $this->editHiddenSources = LibrarySources::hiddenFor($client);
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editType' => ['required', 'in:single,multi'],
            'editDeviceIds' => ['array'],
            'editDeviceIds.*' => ['integer', 'exists:devices,id'],
            'editRoles' => ['array'],
            'editRoles.*' => ['string', 'in:'.implode(',', NodeRoles::names())],
            'editHiddenSources' => ['array'],
            'editHiddenSources.*' => ['string', 'in:'.implode(',', array_keys(LibrarySources::all()))],
        ]);

        ClientAdmin::update(Client::findOrFail($this->editing), [
            'name' => $this->editName,
            'type' => $this->editType,
            'device_ids' => $this->editDeviceIds,
            'roles' => $this->editRoles,
            'hidden_sources' => $this->editHiddenSources,
        ]);

        $this->editing = null;
        session()->flash('success', 'Client updated.');
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
    }

    public function regenerateToken(int $clientId): void
    {
        ClientAdmin::regenerateToken(Client::findOrFail($clientId));
        session()->flash('success', 'API token regenerated.');
    }

    public function delete(int $clientId): void
    {
        $client = Client::findOrFail($clientId);
        $name = $client->name ?? "Client #{$clientId}";
        ClientAdmin::delete($client);
        session()->flash('success', "{$name} removed.");
    }

    public function render()
    {
        return view('livewire.client-manager', [
            'clients' => ClientAdmin::all(),
            'allDevices' => Device::orderBy('device_name')->get(),
            'librarySources' => LibrarySources::all(),
        ]);
    }
}
