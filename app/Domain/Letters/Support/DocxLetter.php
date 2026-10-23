<?php

namespace App\Domain\Letters\Support;

use App\Domain\Notifications\Services\TemplateRenderer;
use DOMDocument;
use Illuminate\Support\Arr;
use RuntimeException;
use ZipArchive;

/**
 * Fills a client's own Word (.docx) letter: {{ variables }} in the body, headers, footers and notes are replaced with
 * the employee's values, keeping the client's layout and letterhead. Word often splits a typed {{ variable }} over
 * several runs (spell-check, formatting); those runs are joined first, keeping the first run's formatting.
 * {{ salary.annexure }} on a line of its own becomes a table; {{ signatory.signature }} becomes the signature image.
 * Every part written is checked to be well-formed XML, so a file Word cannot open is never produced.
 */
final class DocxLetter
{
    /** Parts that can hold text. */
    private const PARTS = '~^word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$~';

    /** Tags a joined {{ variable }} may not span (a paragraph, table, field, link, drawing, text box…). */
    private const STRUCTURE = '~^</?(w:(p|tbl|tr|tc|hyperlink|fldSimple|fldChar|instrText|sdt|sdtContent|drawing|txbxContent|pict|object|smartTag|customXml|ins|del|moveFrom|moveTo)|mc:|v:|wp:|a:)[\s>/]~';

    private const PLACEHOLDER = '\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}';

    /** Uncompressed size above which an upload is refused (guards against zip bombs). */
    private const MAX_UNCOMPRESSED = 30 * 1024 * 1024;

    /** Why the file cannot be used as a template, or null when it can. */
    public static function problem(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return 'The file is not a Word (.docx) document.';
        }
        try {
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $total += (int) ($zip->statIndex($i)['size'] ?? 0);
            }
            if ($total > self::MAX_UNCOMPRESSED) {
                return 'The Word file is too large once opened.';
            }
            $document = $zip->getFromName('word/document.xml');
            if ($document === false) {
                return 'The file is not a Word (.docx) document. Save it from Word as “Word Document (.docx)”.';
            }
            if ($zip->locateName('word/vbaProject.bin') !== false) {
                return 'The file contains macros. Save it as a plain Word Document (.docx).';
            }

            foreach (self::parts($zip) as $name) {
                $xml = (string) $zip->getFromName($name);
                if (! self::wellFormed($xml)) {
                    return 'The Word file is damaged and cannot be read.';
                }
                $xml = self::joinRuns($xml);
                preg_match_all('~'.self::PLACEHOLDER.'~', $xml, $fillable);
                $split = array_diff(LetterVariables::usedIn(self::plain($xml)), $fillable[1]);
                if ($split !== []) {
                    return 'In the Word file, '.collect($split)->map(fn (string $v) => LetterVariables::tag($v))->implode(', ').' is broken up by a link, field or table. Delete it and type it again in one go.';
                }
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    /** @return list<string> the {{ variables }} the file uses */
    public static function variables(string $path): array
    {
        return LetterVariables::usedIn(self::text($path));
    }

    /** The file's text (body, then headers and footers), for search, checking and the approval preview. */
    public static function text(string $path): string
    {
        $zip = self::open($path);
        $texts = [];
        $names = self::parts($zip);
        usort($names, fn (string $a, string $b) => [$a !== 'word/document.xml', $a] <=> [$b !== 'word/document.xml', $b]);
        foreach ($names as $name) {
            $texts[] = self::plain(self::joinRuns((string) $zip->getFromName($name)));
        }
        $zip->close();

        return trim(implode("\n\n", array_filter($texts)));
    }

    /**
     * The filled document's bytes.
     *
     * @param  array<string, mixed>  $context
     */
    public static function render(string $path, array $context): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'letter');
        copy($path, $tmp);
        $zip = self::open($tmp);
        $signature = null;
        $drawings = 0;

        try {
            foreach (self::parts($zip) as $name) {
                $xml = (string) $zip->getFromName($name);
                if (! self::wellFormed($xml)) {
                    throw new RuntimeException('The Word template is damaged and cannot be read. Open it in Word, save it again and upload it.');
                }
                $xml = self::blocks(self::joinRuns($xml), $context);
                $usesSignature = false;
                $xml = (string) preg_replace_callback('~'.self::PLACEHOLDER.'~', function (array $m) use ($context, &$usesSignature, &$signature, &$drawings) {
                    $value = Arr::get($context, $m[1]);
                    if ($value instanceof SignatureImage) {
                        [$usesSignature, $signature] = [true, $value];

                        return '</w:t></w:r><w:r>'.self::drawing($value, 7000 + ++$drawings).'</w:r><w:r><w:t xml:space="preserve">';
                    }

                    return str_replace("\n", '</w:t><w:br/><w:t xml:space="preserve">', htmlspecialchars(TemplateRenderer::format($value), ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                }, $xml);
                if (! self::wellFormed($xml)) {
                    throw new RuntimeException('The Word template could not be filled: a variable in '.basename($name).' is broken up by a link, field or table. Delete it and type it again in one go.');
                }
                $zip->addFromString($name, $xml);
                if ($usesSignature) {
                    self::relate($zip, $name, $signature);
                }
            }
            if ($signature !== null) {
                $zip->addFromString('word/media/peopleos-signature.'.$signature->extension(), $signature->bytes);
                self::contentType($zip, $signature);
            }
        } finally {
            $zip->close();
        }
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /** A starter Word template that uses the main variables, the annexure and the signature, to edit in Word. */
    public static function sample(): string
    {
        $p = fn (string $text, bool $bold = false, ?string $align = null) => '<w:p><w:pPr><w:spacing w:after="120"/>'.($align ? '<w:jc w:val="'.$align.'"/>' : '').'</w:pPr><w:r>'.($bold ? '<w:rPr><w:b/></w:rPr>' : '').'<w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1).'</w:t></w:r></w:p>';
        $body = implode('', [
            $p('{{ company.name }}', true, 'center'),
            $p('Ref: {{ letter.number }}'),
            $p('Date: {{ letter.date }}'),
            $p(''),
            $p('{{ employee.salutation }}. {{ employee.name }}'),
            $p('{{ address.current.full }}'),
            $p(''),
            $p('Subject: Appointment as {{ employee.designation }}', true),
            $p('Dear {{ employee.first_name }},'),
            $p('We are pleased to appoint you as {{ employee.designation }} in the {{ employee.department }} department of {{ company.name }}, at {{ employee.location }}, with effect from {{ employee.joining_date }}.'),
            $p('Your annual cost to company will be {{ salary.currency }} {{ salary.ctc.annual }} ({{ salary.ctc.annual_words }}), that is {{ salary.currency }} {{ salary.ctc.monthly }} a month, as set out in the annexure.'),
            $p('You will report to {{ manager.name }}, {{ manager.designation }}.'),
            $p(''),
            $p('For {{ company.name }}', true),
            $p('{{ signatory.signature }}'),
            $p('{{ signatory.name }}', true),
            $p('{{ signatory.designation }}'),
            '<w:p><w:r><w:br w:type="page"/></w:r></w:p>',
            $p('Annexure: salary structure of {{ employee.name }} ({{ employee.code }})', true),
            $p('{{ salary.annexure }}'),
            $p('Gross salary {{ salary.gross.monthly }} a month; {{ salary.gross.annual }} a year.'),
        ]);
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>'.$body.'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1260" w:bottom="1440" w:left="1260" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>',
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'letter');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /** Joins the runs Word split a {{ variable }} over, when only run-level tags lie between its characters. */
    private static function joinRuns(string $xml): string
    {
        $xml = (string) preg_replace('~<w:t>~', '<w:t xml:space="preserve">', $xml);

        return (string) preg_replace_callback('~\{(?:<[^>]+>)*\{(?:[^{}<]|<[^>]+>)*?\}(?:<[^>]+>)*\}~', function (array $m) {
            preg_match_all('~<[^>]+>~', $m[0], $tags);
            foreach ($tags[0] as $tag) {
                if (preg_match(self::STRUCTURE, $tag)) {
                    return $m[0];
                }
            }

            return (string) preg_replace('~<[^>]+>~', '', $m[0]);
        }, $xml);
    }

    /** A paragraph holding only {{ salary.annexure }} becomes the annexure table. */
    private static function blocks(string $xml, array $context): string
    {
        return (string) preg_replace_callback('~<w:p(?:\s[^>]*)?(?<!/)>(?:(?!</w:p>).)*?</w:p>~s', function (array $m) use ($context) {
            if (str_contains($m[0], '<w:txbxContent') || ! preg_match('~^\s*'.self::PLACEHOLDER.'\s*$~', self::plain($m[0]), $p)) {
                return $m[0];
            }
            $value = Arr::get($context, $p[1]);

            return $value instanceof SalaryAnnexure ? self::table($value).'<w:p/>' : $m[0];
        }, $xml);
    }

    private static function table(SalaryAnnexure $annexure): string
    {
        $widths = [4800, 2200, 2200];
        $cell = function (string $text, int $col, bool $bold = false, bool $right = false, bool $span = false, bool $shade = false) use ($widths): string {
            return '<w:tc><w:tcPr><w:tcW w:w="'.($span ? array_sum($widths) : $widths[$col]).'" w:type="dxa"/>'.($span ? '<w:gridSpan w:val="3"/>' : '')
                .($shade ? '<w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>' : '').'</w:tcPr>'
                .'<w:p><w:pPr><w:spacing w:before="40" w:after="40"/>'.($right ? '<w:jc w:val="right"/>' : '').'</w:pPr>'
                .'<w:r>'.($bold ? '<w:rPr><w:b/></w:rPr>' : '').'<w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t></w:r></w:p></w:tc>';
        };
        $currency = $annexure->currency;
        $rows = '<w:tr><w:trPr><w:tblHeader/></w:trPr>'.$cell('Component', 0, true, false, false, true).$cell("Monthly ({$currency})", 1, true, true, false, true).$cell("Yearly ({$currency})", 2, true, true, false, true).'</w:tr>';
        foreach ($annexure->rows as $row) {
            $rows .= match ($row['kind']) {
                'heading' => '<w:tr>'.$cell($row['label'], 0, true, false, true, true).'</w:tr>',
                'note' => '<w:tr>'.$cell($row['label'], 0, false, false, true).'</w:tr>',
                default => '<w:tr>'.$cell($row['label'], 0, $row['kind'] === 'total').$cell(SalaryAnnexure::money($row['monthly']), 1, $row['kind'] === 'total', true).$cell(SalaryAnnexure::money($row['annual']), 2, $row['kind'] === 'total', true).'</w:tr>',
            };
        }
        $borders = implode('', array_map(fn (string $side) => '<w:'.$side.' w:val="single" w:sz="4" w:space="0" w:color="999999"/>', ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']));

        return '<w:tbl><w:tblPr><w:tblW w:w="'.array_sum($widths).'" w:type="dxa"/><w:tblBorders>'.$borders.'</w:tblBorders>'
            .'<w:tblCellMar><w:left w:w="100" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr>'
            .'<w:tblGrid>'.implode('', array_map(fn (int $w) => '<w:gridCol w:w="'.$w.'"/>', $widths)).'</w:tblGrid>'.$rows.'</w:tbl>';
    }

    /** An inline picture (namespaces declared on the elements, so any part can hold it). */
    private static function drawing(SignatureImage $image, int $id): string
    {
        [$width, $height] = $image->printedSize();
        [$cx, $cy] = [$width * 9525, $height * 9525];

        return '<w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" distT="0" distB="0" distL="0" distR="0">'
            .'<wp:extent cx="'.$cx.'" cy="'.$cy.'"/><wp:docPr id="'.$id.'" name="Signature '.$id.'"/>'
            .'<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="0" name="signature.'.$image->extension().'"/><pic:cNvPicPr/></pic:nvPicPr>'
            .'<pic:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rIdPeopleOSSignature"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
            .'</a:graphicData></a:graphic></wp:inline></w:drawing>';
    }

    /** The part's relationship to the signature image. */
    private static function relate(ZipArchive $zip, string $part, SignatureImage $image): void
    {
        $name = 'word/_rels/'.basename($part).'.rels';
        $rels = $zip->getFromName($name) ?: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
        if (! str_contains($rels, 'Id="rIdPeopleOSSignature"')) {
            $rel = '<Relationship Id="rIdPeopleOSSignature" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/peopleos-signature.'.$image->extension().'"/>';
            $rels = str_contains($rels, '</Relationships>') ? str_replace('</Relationships>', $rel.'</Relationships>', $rels) : (string) preg_replace('~<Relationships([^>]*)/>~', '<Relationships$1>'.$rel.'</Relationships>', $rels);
        }
        $zip->addFromString($name, $rels);
    }

    private static function contentType(ZipArchive $zip, SignatureImage $image): void
    {
        $types = (string) $zip->getFromName('[Content_Types].xml');
        $extension = $image->extension();
        if (! preg_match('~<Default\s+Extension="'.$extension.'"~i', $types)) {
            $types = str_replace('</Types>', '<Default Extension="'.$extension.'" ContentType="'.$image->mime.'"/></Types>', $types);
            $zip->addFromString('[Content_Types].xml', $types);
        }
    }

    private static function plain(string $xml): string
    {
        $xml = (string) preg_replace(['~<w:tab/>|</w:tc>~', '~</w:p>|<w:br/>~'], ["\t", "\n"], $xml);

        return trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    /** @return list<string> */
    private static function parts(ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match(self::PARTS, $name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The Word template could not be opened.');
        }

        return $zip;
    }

    private static function wellFormed(string $xml): bool
    {
        $previous = libxml_use_internal_errors(true);
        $ok = (new DOMDocument)->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT) !== false;
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok;
    }
}
