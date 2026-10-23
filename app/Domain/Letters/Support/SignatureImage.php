<?php

namespace App\Domain\Letters\Support;

use Stringable;

/** The signing authority's signature for {{ signatory.signature }}: embedded in the letter, never linked. */
final class SignatureImage implements Stringable
{
    /** Printed height of the signature; the width keeps the image's proportions. */
    public const HEIGHT_PX = 60;

    public function __construct(public readonly string $bytes, public readonly string $mime, public readonly int $width, public readonly int $height) {}

    public function extension(): string
    {
        return $this->mime === 'image/jpeg' ? 'jpeg' : 'png';
    }

    /** @return array{0: int, 1: int} printed width and height in pixels */
    public function printedSize(): array
    {
        return [(int) round(self::HEIGHT_PX * $this->width / max(1, $this->height)), self::HEIGHT_PX];
    }

    public function toHtml(): string
    {
        [$width, $height] = $this->printedSize();

        return '<img class="signature" alt="Signature" width="'.$width.'" height="'.$height.'" src="data:'.$this->mime.';base64,'.base64_encode($this->bytes).'">';
    }

    public function __toString(): string
    {
        return '';
    }
}
