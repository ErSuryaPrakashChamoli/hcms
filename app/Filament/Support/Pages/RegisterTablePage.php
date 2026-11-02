<?php

namespace App\Filament\Support\Pages;

use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;

/**
 * HROne parity TX-4: a custom page whose table is a register with WO-1's HasRegisterTools. The table trait sits on this
 * parent so its boot hook builds the table before HasRegisterTools (used on the page itself) applies a preset or default
 * view to it — the order resource list pages get from ListRecords.
 */
abstract class RegisterTablePage extends Page implements HasTable
{
    use InteractsWithTable;
}
