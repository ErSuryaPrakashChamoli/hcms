<?php

namespace App\Domain\Workflow\Services;

/** Structural validation of a workflow definition before it can be published. */
final class WorkflowDefinition
{
    /**
     * @param  array<string, mixed>  $definition
     * @return list<string> errors; empty when valid
     */
    public function validate(array $definition): array
    {
        $errors = [];
        $nodes = array_values($definition['nodes'] ?? []);
        $edges = array_values($definition['edges'] ?? []);
        $types = array_keys(config('peopleos.workflows.node_types'));
        $ids = [];

        foreach ($nodes as $i => $node) {
            $id = $node['id'] ?? null;
            $type = $node['type'] ?? null;

            if (! $id) {
                $errors[] = 'Node #'.($i + 1).' has no id.';

                continue;
            }

            if (isset($ids[$id])) {
                $errors[] = "Duplicate node id [{$id}].";
            }

            $ids[$id] = $type;

            if (! in_array($type, $types, true)) {
                $errors[] = "Node [{$id}] has unknown type [{$type}].";
            }

            if ($type === 'approval' && empty($node['config']['approver']['type']) && empty($node['config']['approvers'])) {
                $errors[] = "Approval node [{$id}] needs an approver.";
            }

            if ($type === 'condition' && empty($node['config']['conditions'])) {
                $errors[] = "Condition node [{$id}] needs at least one condition.";
            }

            if ($type === 'webhook' && empty($node['config']['url'])) {
                $errors[] = "Webhook node [{$id}] needs a URL.";
            }

            // HROne setup parity P5: deciding automatically is for the last approval level only.
            $autoDecides = collect($node['config']['escalation'] ?? [])->contains(fn ($step) => ($step['action'] ?? null) === 'auto_decide');
            if ($autoDecides && ($type !== 'approval' || ! $this->isLastApproval($definition, (string) $id))) {
                $errors[] = "Node [{$id}]: \"Decide automatically\" is allowed only on the last approval step.";
            }
        }

        $starts = array_keys(array_filter($ids, fn ($t) => $t === 'start'));
        $ends = array_keys(array_filter($ids, fn ($t) => $t === 'end'));

        if (count($starts) !== 1) {
            $errors[] = 'A workflow needs exactly one Start node.';
        }

        if ($ends === []) {
            $errors[] = 'A workflow needs at least one End node.';
        }

        foreach ($edges as $edge) {
            foreach (['from', 'to'] as $end) {
                if (! isset($ids[$edge[$end] ?? ''])) {
                    $errors[] = "Edge references unknown node [{$edge[$end]}].";
                }
            }
        }

        foreach ($ids as $id => $type) {
            $outgoing = array_filter($edges, fn ($e) => ($e['from'] ?? null) === $id);
            $labels = array_map(fn ($e) => $e['label'] ?? null, $outgoing);

            if ($type === 'end') {
                continue;
            }

            if ($outgoing === []) {
                $errors[] = "Node [{$id}] has no outgoing edge.";
            }

            if ($type === 'condition' && (! in_array('yes', $labels, true) || ! in_array('no', $labels, true))) {
                $errors[] = "Condition node [{$id}] needs a 'yes' and a 'no' edge.";
            }
        }

        return $errors;
    }

    /** HROne setup parity P5: an approval node from which no other approval node can be reached (the last level). */
    public function isLastApproval(array $definition, string $nodeId): bool
    {
        $types = collect($definition['nodes'] ?? [])->mapWithKeys(fn ($n) => [(string) ($n['id'] ?? '') => $n['type'] ?? null])->all();
        if (($types[$nodeId] ?? null) !== 'approval') {
            return false;
        }
        $edges = collect($definition['edges'] ?? []);
        $seen = [$nodeId => true];
        $queue = [$nodeId];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($edges->where('from', $current)->pluck('to') as $next) {
                $next = (string) $next;
                if (isset($seen[$next])) {
                    continue;
                }
                if (($types[$next] ?? null) === 'approval') {
                    return false;
                }
                $seen[$next] = true;
                $queue[] = $next;
            }
        }

        return true;
    }
}
