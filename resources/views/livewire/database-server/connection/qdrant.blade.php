@props(['form', 'isEdit' => false])

<div class="grid gap-4 md:grid-cols-2">
    <x-input
        wire:model="form.host"
        :label="__('Host')"
        placeholder="qdrant"
        type="text"
        required
    />

    <x-input
        wire:model="form.port"
        :label="__('Port')"
        placeholder="6333"
        type="number"
        min="1"
        max="65535"
        required
    />
</div>

<x-password
    wire:model="form.password"
    :label="__('API key')"
    :placeholder="$isEdit ? __('Leave blank to keep current') : __('Optional')"
    :hint="__('Sent as the api-key header. Leave empty when Qdrant has no API key.')"
    autocomplete="off"
/>
