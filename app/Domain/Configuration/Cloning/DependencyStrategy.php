<?php

namespace App\Domain\Configuration\Cloning;

/** What a clone does with one dependency of its source. */
enum DependencyStrategy: string
{
    case CloneWithParent = 'clone_with_parent';
    case Reuse = 'reuse';
    case CloneToo = 'clone_too';
    case Map = 'map';
    case Reselect = 'reselect';
    case Manual = 'manual';
    case Excluded = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::CloneWithParent => 'Copied with it',
            self::Reuse => 'Reused as it is',
            self::CloneToo => 'Cloned too',
            self::Map => 'Mapped to the destination\'s own',
            self::Reselect => 'Chosen again',
            self::Manual => 'Set up by hand afterwards',
            self::Excluded => 'Not copied',
        };
    }
}
