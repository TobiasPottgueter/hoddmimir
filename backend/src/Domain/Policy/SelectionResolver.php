<?php

declare(strict_types=1);

namespace App\Domain\Policy;

final readonly class SelectionResolver
{
    public function resolve(
        SelectionValue $global,
        SelectionValue $connection,
        SelectionValue $cluster,
        SelectionValue $node,
        SelectionValue $guest,
    ): EffectiveSelection {
        $values = [
            SelectionScope::Global->value => $global,
            SelectionScope::Connection->value => $connection,
            SelectionScope::Cluster->value => $cluster,
            SelectionScope::Node->value => $node,
            SelectionScope::Guest->value => $guest,
        ];

        foreach (array_reverse($values, true) as $scope => $value) {
            if (SelectionValue::Exclude === $value) {
                return new EffectiveSelection(false, SelectionScope::from($scope), $value);
            }
        }

        foreach (array_reverse($values, true) as $scope => $value) {
            if (SelectionValue::Include === $value) {
                return new EffectiveSelection(true, SelectionScope::from($scope), $value);
            }
        }

        return new EffectiveSelection(false, null, SelectionValue::Inherit);
    }
}
