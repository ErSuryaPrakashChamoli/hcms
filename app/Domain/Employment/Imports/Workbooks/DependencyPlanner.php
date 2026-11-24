<?php

namespace App\Domain\Employment\Imports\Workbooks;

use RuntimeException;

/**
 * Master uploads: the order worksheets are checked and run in, worked out from what each one creates (`provides`) and
 * refers to (`needs`): a worksheet runs after every other worksheet that creates something it refers to. Worksheets
 * that do not depend on each other keep the workbook's own order. A worksheet that refers to its own kind (sub branches
 * under branches added in the same tab) is not its own prerequisite: the upload already accepts a parent listed in an
 * earlier row of the same file.
 */
final class DependencyPlanner
{
    /**
     * The run order (`order`), each worksheet's level (`stages`: 1-based; worksheets of one level do not depend on each
     * other) and what each one waits for (`after`).
     *
     * @param  array<string, array{provides?: array<string, string>, needs?: array<string, mixed>}>  $sheets  title => definition, in workbook order
     * @return array{order: list<string>, stages: array<string, int>, after: array<string, list<string>>}
     */
    public static function plan(array $sheets): array
    {
        $titles = array_keys($sheets);
        $position = array_flip($titles);
        $after = array_fill_keys($titles, []);
        foreach ($sheets as $title => $sheet) {
            foreach (array_keys($sheet['needs'] ?? []) as $kind) {
                foreach ($sheets as $other => $provider) {
                    if ($other !== $title && array_key_exists($kind, $provider['provides'] ?? [])) {
                        $after[$title][$other] = $other;
                    }
                }
            }
        }
        $after = array_map(fn (array $titles) => array_values($titles), $after);

        // Levels first: a worksheet's level is one more than the highest level it waits for.
        $stages = [];
        $remaining = $after;
        while ($remaining !== []) {
            $ready = array_keys(array_filter($remaining, fn (array $waits) => array_diff($waits, array_keys($stages)) === []));
            if ($ready === []) {
                throw new RuntimeException('These worksheets depend on each other in a circle: '.implode(', ', array_keys($remaining)).'.');
            }
            foreach ($ready as $title) {
                $stages[$title] = 1 + max([0, ...array_map(fn (string $prerequisite) => $stages[$prerequisite], $after[$title])]);
                unset($remaining[$title]);
            }
        }
        // Then level by level, each level in the workbook's own order, so the run reads as the Read me lists it.
        $order = $titles;
        usort($order, fn (string $a, string $b) => [$stages[$a], $position[$a]] <=> [$stages[$b], $position[$b]]);

        return ['order' => $order, 'stages' => $stages, 'after' => $after];
    }
}
