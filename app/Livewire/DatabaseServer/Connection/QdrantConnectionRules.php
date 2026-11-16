<?php

namespace App\Livewire\DatabaseServer\Connection;

use App\Livewire\DatabaseServer\Form;
use App\Rules\SafeHost;

/**
 * Qdrant collections are reached over HTTP. The API key is optional and is
 * sent as the api-key header; a username is not used.
 */
class QdrantConnectionRules extends ClientServerConnectionRules
{
    public function rules(Form $form): array
    {
        return array_merge(parent::rules($form), [
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
        ]);
    }

    public function testConnectionRules(Form $form): array
    {
        return [
            'host' => ['required', 'string', 'max:255', new SafeHost],
            'port' => 'required|integer|min:1|max:65535',
        ];
    }
}
