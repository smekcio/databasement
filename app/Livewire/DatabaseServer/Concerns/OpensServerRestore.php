<?php

namespace App\Livewire\DatabaseServer\Concerns;

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;

/**
 * Restoring onto a server: the restore wizard, or for Redis/Valkey, which has
 * no automated restore, the manual instructions in
 * `partials.redis-restore-modal`.
 */
trait OpensServerRestore
{
    public bool $showRedisRestoreModal = false;

    protected function openRestoreFor(DatabaseServer $server): void
    {
        $this->authorize('restore', $server);

        if ($server->database_type === DatabaseType::REDIS) {
            $this->showRedisRestoreModal = true;

            return;
        }

        if (! $server->database_type->supportsRestore()) {
            $this->warning(__('Automated restore is not supported for :type. Please restore manually.', [
                'type' => $server->database_type->label(),
            ]));

            return;
        }

        $this->dispatch('open-restore-modal', mode: 'from-server', targetServerId: $server->id);
    }
}
