<?php

namespace App\Livewire;

use App\Models\Client;
use App\Models\Device;
use Livewire\Component;

class ClientManager extends Component
{
    public ?int $approvingId = null;

    public string $approveName = '';

    public string $approveType = 'single';

    public ?int $editing = null;

    public string $editName = '';

    public string $editType = 'single';

    /** @var array<int> */
    public array $editDeviceIds = [];

    public function startApprove(int $clientId): void
    {
        $this->approvingId = $clientId;
        $this->approveName = '';
        $this->approveType = 'single';
    }

    public function approve(): void
    {
        $this->validate([
            'approveType' => ['required', 'in:single,multi'],
        ]);

        $client = Client::findOrFail($this->approvingId);
        $client->update([
            'status' => 'approved',
            'type' => $this->approveType,
            'name' => trim($this->approveName) ?: null,
            'api_token' => Client::generateToken(),
            'approved_at' => now(),
        ]);

        $this->approvingId = null;
        session()->flash('success', 'Client approved.');
    }

    public function reject(int $clientId): void
    {
        Client::findOrFail($clientId)->delete();
        session()->flash('success', 'Client registration rejected and removed.');
    }

    public function startEdit(int $clientId): void
    {
        $client = Client::with('devices')->findOrFail($clientId);
        $this->editing = $clientId;
        $this->editName = $client->name ?? '';
        $this->editType = $client->type;
        $this->editDeviceIds = $client->devices->pluck('id')->map(fn ($id) => (int) $id)->toArray();
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editType' => ['required', 'in:single,multi'],
            'editDeviceIds' => ['array'],
            'editDeviceIds.*' => ['integer', 'exists:devices,id'],
        ]);

        $client = Client::findOrFail($this->editing);

        $deviceIds = array_map('intval', $this->editDeviceIds);
        if ($this->editType === 'single' && count($deviceIds) > 1) {
            $deviceIds = [reset($deviceIds)];
        }

        $client->update([
            'name' => trim($this->editName) ?: null,
            'type' => $this->editType,
        ]);
        $client->devices()->sync($deviceIds);

        $this->editing = null;
        session()->flash('success', 'Client updated.');
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
    }

    public function regenerateToken(int $clientId): void
    {
        Client::findOrFail($clientId)->update(['api_token' => Client::generateToken()]);
        session()->flash('success', 'API token regenerated.');
    }

    public function delete(int $clientId): void
    {
        $client = Client::findOrFail($clientId);
        $name = $client->name ?? "Client #{$clientId}";
        $client->delete();
        session()->flash('success', "{$name} removed.");
    }

    public function render()
    {
        return view('livewire.client-manager', [
            'clients' => Client::with('devices')
                ->orderByRaw("status = 'pending' DESC")
                ->orderBy('created_at', 'desc')
                ->get(),
            'allDevices' => Device::orderBy('device_name')->get(),
        ]);
    }
}
