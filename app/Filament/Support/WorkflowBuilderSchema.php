<?php

namespace App\Filament\Support;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Support\Validation\SafeOutboundUrl;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;

/**
 * The no-code workflow builder (§44) as Filament components over the version's definition:
 * a list of nodes with type-specific settings, and a list of edges between node ids.
 */
final class WorkflowBuilderSchema
{
    /** @return array<int, Component> */
    public static function components(): array
    {
        return [
            Repeater::make('definition.nodes')
                ->label('Nodes')
                ->schema([
                    TextInput::make('id')->label('Node id')->required()->maxLength(64)->regex('/^[a-z][a-z0-9_]*$/')->distinct()
                        ->helperText('Short stable key used by edges, e.g. manager_approval'),
                    Select::make('type')->options(config('peopleos.workflows.node_types'))->required()->live(),
                    TextInput::make('name')->required()->maxLength(255)->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, callable $set, Get $get) => filled($get('id')) ?: $set('id', Str::snake(Str::limit($state, 40, '')))),
                    ...self::approvalFields(),
                    ...self::conditionFields(),
                    ...self::taskFields(),
                    ...self::notificationFields(),
                    ...self::waitFields(),
                    ...self::webhookFields(),
                    ...self::automationFields(),
                ])
                ->columns(3)
                ->collapsible()
                ->reorderable()
                ->itemLabel(fn (array $state) => trim(($state['name'] ?? '').' ('.(config('peopleos.workflows.node_types')[$state['type'] ?? ''] ?? '?').')'))
                ->columnSpanFull(),
            Repeater::make('definition.edges')
                ->label('Edges (who goes where)')
                ->schema([
                    Select::make('from')->options(fn (Get $get) => self::nodeOptions($get('../../definition.nodes') ?? $get('../../nodes')))->required(),
                    Select::make('to')->options(fn (Get $get) => self::nodeOptions($get('../../definition.nodes') ?? $get('../../nodes')))->required(),
                    TextInput::make('label')->placeholder('yes / no / approved / rejected')->maxLength(32)
                        ->helperText('Condition nodes need yes and no; approvals use approved and rejected (rejected defaults to End).'),
                ])
                ->columns(3)
                ->itemLabel(fn (array $state) => ($state['from'] ?? '?').' → '.($state['to'] ?? '?').(filled($state['label'] ?? null) ? " [{$state['label']}]" : ''))
                ->columnSpanFull(),
        ];
    }

    /** @return array<int, Component> */
    public static function approverSpec(string $prefix, string $label = 'Approver'): array
    {
        return [
            Select::make("{$prefix}.type")->label("{$label} type")->options(config('peopleos.workflows.approver_types'))->live(),
            Select::make("{$prefix}.role_id")->label('Role')->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn (Get $get) => $get("{$prefix}.type") === 'role')->required(fn (Get $get) => $get("{$prefix}.type") === 'role'),
            Select::make("{$prefix}.user_id")->label('User')->options(fn () => User::query()->forCurrentTenant()->orderBy('name')->pluck('name', 'id')->all())->searchable()
                ->visible(fn (Get $get) => $get("{$prefix}.type") === 'user')->required(fn (Get $get) => $get("{$prefix}.type") === 'user'),
            TextInput::make("{$prefix}.level")->label('Levels up')->numeric()->minValue(1)->default(2)
                ->visible(fn (Get $get) => $get("{$prefix}.type") === 'hierarchy_level'),
            TextInput::make("{$prefix}.field")->label('Context field holding the user id')->placeholder('data.approver_user_id')
                ->visible(fn (Get $get) => $get("{$prefix}.type") === 'field'),
        ];
    }

    private static function approvalFields(): array
    {
        $is = fn (Get $get) => $get('type') === 'approval';

        return [
            Fieldset::make('Approval')->visible($is)->columns(3)->columnSpanFull()->schema([
                Select::make('config.mode')->label('Mode')->options(config('peopleos.workflows.approval_modes'))->default('single')->live(),
                TextInput::make('config.sla_hours')->label('SLA (hours)')->numeric()->minValue(1)->default(config('peopleos.workflows.default_sla_hours')),
                TextInput::make('config.title')->label('Task title')->placeholder('Approve {{ workflow.name }} for {{ employee.name }}')->maxLength(255),
                ...array_map(fn (Component $c) => $c->visible(fn (Get $get) => ($get('config.mode') ?? 'single') === 'single'), self::approverSpec('config.approver')),
                Repeater::make('config.approvers')->label('Approvers')->schema(self::approverSpec('', 'Approver'))->columns(3)
                    ->visible(fn (Get $get) => ($get('config.mode') ?? 'single') !== 'single')->columnSpanFull(),
                self::escalationRepeater(),
            ]),
        ];
    }

    private static function conditionFields(): array
    {
        return [
            Fieldset::make('Condition')->visible(fn (Get $get) => $get('type') === 'condition')->columns(1)->columnSpanFull()->schema([
                Radio::make('config.match')->options(['all' => 'All conditions', 'any' => 'Any condition'])->default('all')->inline(),
                Repeater::make('config.conditions')->schema([
                    TextInput::make('field')->required()->placeholder('department_id, tenure_months, data.amount, subject.status')
                        ->helperText('Employee dimensions, subject.* attributes, data.* form answers, or context keys.'),
                    Select::make('operator')->options(config('peopleos.rules.operators'))->required()->default('equals'),
                    TextInput::make('value')->placeholder('Comma-separate for "any of"'),
                ])->columns(3)->minItems(1),
            ]),
        ];
    }

    private static function taskFields(): array
    {
        return [
            Fieldset::make('Task')->visible(fn (Get $get) => $get('type') === 'task')->columns(3)->columnSpanFull()->schema([
                TextInput::make('config.title')->label('Task title')->maxLength(255)->columnSpan(2),
                TextInput::make('config.sla_hours')->label('Due in (hours)')->numeric()->minValue(1)->default(config('peopleos.workflows.default_sla_hours')),
                Textarea::make('config.instructions')->rows(2)->columnSpanFull(),
                ...self::approverSpec('config.assignee', 'Assignee'),
                self::escalationRepeater(),
            ]),
        ];
    }

    private static function notificationFields(): array
    {
        return [
            Fieldset::make('Notification')->visible(fn (Get $get) => $get('type') === 'notification')->columns(2)->columnSpanFull()->schema([
                Repeater::make('config.audience')->schema([
                    Select::make('type')->options(config('peopleos.notifications.audience_types'))->required()->live(),
                    Select::make('role_id')->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all())->visible(fn (Get $get) => $get('type') === 'role'),
                    Select::make('user_id')->options(fn () => User::query()->forCurrentTenant()->orderBy('name')->pluck('name', 'id')->all())->searchable()->visible(fn (Get $get) => $get('type') === 'user'),
                ])->columns(3)->minItems(1)->columnSpanFull(),
                CheckboxList::make('config.channels')->options(collect(config('peopleos.notifications.channels'))->map(fn ($c) => $c['label'])->all())->default(['in_app'])->columns(3),
                TextInput::make('config.subject')->required()->maxLength(255)->placeholder('{{ employee.name }} has joined'),
                Textarea::make('config.body')->rows(3)->columnSpanFull()->placeholder('Hello {{ employee.first_name }}, …'),
            ]),
        ];
    }

    private static function waitFields(): array
    {
        return [
            TextInput::make('config.hours')->label('Wait (hours)')->numeric()->minValue(0.1)->default(24)->visible(fn (Get $get) => $get('type') === 'wait'),
        ];
    }

    private static function webhookFields(): array
    {
        return [
            Fieldset::make('Webhook')->visible(fn (Get $get) => $get('type') === 'webhook')->columns(3)->columnSpanFull()->schema([
                TextInput::make('config.url')->url()->rule(new SafeOutboundUrl)->required()->columnSpan(2),
                Select::make('config.method')->options(['POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH'])->default('POST'),
                KeyValue::make('config.headers')->keyLabel('Header')->valueLabel('Value')->columnSpanFull(),
            ]),
        ];
    }

    private static function automationFields(): array
    {
        return [
            KeyValue::make('config.set')->label('Set on the subject record')->keyLabel('Field')->valueLabel('Value (templates allowed)')
                ->visible(fn (Get $get) => $get('type') === 'automation')->columnSpanFull(),
        ];
    }

    private static function escalationRepeater(): Repeater
    {
        return Repeater::make('config.escalation')->label('Escalation steps')->schema([
            TextInput::make('after_hours')->numeric()->minValue(1)->required(),
            Select::make('action')->options(config('peopleos.workflows.escalation_actions'))->required()->live(),
            Select::make('role_id')->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all())->visible(fn (Get $get) => $get('action') === 'escalate_to_role'),
        ])->columns(3)->columnSpanFull()->defaultItems(0);
    }

    /** @return array<string, string> */
    private static function nodeOptions(?array $nodes): array
    {
        return collect($nodes ?? [])->filter(fn ($n) => filled($n['id'] ?? null))->mapWithKeys(fn ($n) => [$n['id'] => ($n['name'] ?? $n['id']).' ('.$n['id'].')'])->all();
    }

    /** Human-readable rendering of a definition for previews. */
    public static function describe(array $definition): array
    {
        $types = config('peopleos.workflows.node_types');
        $lines = [];

        foreach ($definition['nodes'] ?? [] as $node) {
            $edges = collect($definition['edges'] ?? [])->where('from', $node['id'] ?? null)
                ->map(fn ($e) => (filled($e['label'] ?? null) ? "[{$e['label']}] " : '').($e['to'] ?? '?'))->implode(', ');
            $lines[] = sprintf('%s · %s → %s', $types[$node['type'] ?? ''] ?? $node['type'], $node['name'] ?? $node['id'], $edges ?: '∎');
        }

        return $lines;
    }
}
