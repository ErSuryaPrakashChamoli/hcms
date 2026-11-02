@php($posActions = array_filter($actions, fn ($action) => $action->isVisible()))
@if ($posActions !== [])
    <x-filament::actions :actions="$posActions" class="pos-toolbar-actions" />
@endif
