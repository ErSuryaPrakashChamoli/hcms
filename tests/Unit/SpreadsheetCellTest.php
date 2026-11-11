<?php

use App\Support\Csv\SpreadsheetCell;

// Streamline S3: one formula-injection guard for every CSV export.
it('reads a cell that would start a formula as text, and leaves plain numbers and text alone', function () {
    expect(SpreadsheetCell::guard('=HYPERLINK("x")'))->toBe("'=HYPERLINK(\"x\")")
        ->and(SpreadsheetCell::guard('+1+1'))->toBe("'+1+1")
        ->and(SpreadsheetCell::guard('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(SpreadsheetCell::guard("\tcmd"))->toBe("'\tcmd")
        ->and(SpreadsheetCell::guard('-2+3'))->toBe("'-2+3")
        ->and(SpreadsheetCell::guard('-5'))->toBe('-5')
        ->and(SpreadsheetCell::guard('-1250.50'))->toBe('-1250.50')
        ->and(SpreadsheetCell::guard('Asha'))->toBe('Asha')
        ->and(SpreadsheetCell::guard(''))->toBe('')
        ->and(SpreadsheetCell::of(12.5))->toBe('12.50')
        ->and(SpreadsheetCell::of(null))->toBe('')
        ->and(SpreadsheetCell::row(['=x', 3, null, '-4']))->toBe(["'=x", 3, null, '-4']);
});
