<?php

namespace App\Domain\Configuration\Cloning;

/** What a cloned record points at, which decides what a clone may do with it (Deliverable 3, dependency defaults). */
enum ReferenceKind: string
{
    case OwnedChild = 'owned';
    case SharedMaster = 'shared';
    case ReferencedConfiguration = 'configuration';
    case CompanyUnit = 'company_unit';
    case Person = 'person';
    case Role = 'role';
    case Secret = 'secret';
    case Transactional = 'transactional';

    public function label(): string
    {
        return match ($this) {
            self::OwnedChild => 'Part of the record',
            self::SharedMaster => 'Shared by every company',
            self::ReferencedConfiguration => 'Other configuration',
            self::CompanyUnit => 'Belongs to a company',
            self::Person => 'A person',
            self::Role => 'A role',
            self::Secret => 'Secret or identity',
            self::Transactional => 'Transactions or history',
        };
    }

    /** What a clone does with it unless the person chooses otherwise. */
    public function defaultStrategy(): DependencyStrategy
    {
        return match ($this) {
            self::OwnedChild => DependencyStrategy::CloneWithParent,
            self::SharedMaster, self::ReferencedConfiguration, self::Role => DependencyStrategy::Reuse,
            self::CompanyUnit => DependencyStrategy::Map,
            self::Person => DependencyStrategy::Reselect,
            self::Secret, self::Transactional => DependencyStrategy::Excluded,
        };
    }
}
