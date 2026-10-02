<?php

namespace nineteenninetyfour\ghostwriter\images;

use enshrined\svgSanitize\Sanitizer;
use Imagick;
use ImagickPixel;
use InvalidArgumentException;

/**
 * Composes a logo centred on a flat or gradient ground: the kind of image a
 * site uses for a partner, a product or a technology. It is drawn in code
 * and not by an image model, because a logo has to come out exactly as it
 * went in.
 */
class LogoCard
{
    /** Share of the card's width and height the logo may take up. */
    private const MAX_WIDTH = 0.45;

    private const MAX_HEIGHT = 0.3;

    /** Stands in for whether the extension is loaded; for tests. */
    public static ?bool $imagick = null;

    public static function available(): bool
    {
        return self::$imagick ?? extension_loaded('imagick');
    }

    /**
     * @param  string  $logo  The logo file's contents: PNG, WebP or SVG with a transparent ground.
     * @param  string|null  $colour  Hex colour of the ground. Null uses the logo's own main colour.
     * @param  string|null  $colourTo  A second hex colour makes the ground a gradient.
     * @param  bool  $white  Turn the logo white, as on a coloured ground it usually should be.
     * @return array{content: string, colour: string}
     */
    public function compose(string $logo, int $width, int $height, ?string $colour = null, ?string $colourTo = null, bool $white = true, int $angle = 135): array
    {
        if (!self::available()) {
            throw new InvalidArgumentException('Logo cards need the Imagick PHP extension, which this server does not have.');
        }

        $mark = $this->read($logo, $width);
        $colour = $this->hex($colour) ?? $this->mainColour($mark);
        $colourTo = $this->hex($colourTo);

        $card = new Imagick();

        if ($colourTo && $colourTo !== $colour) {
            $card->setOption('gradient:angle', (string) $angle);
            $card->newPseudoImage($width, $height, "gradient:{$colour}-{$colourTo}");
        } else {
            $card->newImage($width, $height, new ImagickPixel($colour));
        }

        if ($white) {
            // Every pixel becomes white; how see-through each is stays.
            $mark->evaluateImage(Imagick::EVALUATE_SET, Imagick::getQuantum(), Imagick::CHANNEL_RED | Imagick::CHANNEL_GREEN | Imagick::CHANNEL_BLUE);
        }

        $mark->resizeImage((int) ($width * self::MAX_WIDTH), (int) ($height * self::MAX_HEIGHT), Imagick::FILTER_LANCZOS, 1, true);

        $card->compositeImage(
            $mark,
            Imagick::COMPOSITE_OVER,
            (int) (($width - $mark->getImageWidth()) / 2),
            (int) (($height - $mark->getImageHeight()) / 2),
        );

        $card->setImageFormat('jpeg');
        $card->setImageCompressionQuality(90);
        $card->stripImage();

        return ['content' => $card->getImageBlob(), 'colour' => $colour];
    }

    private function read(string $logo, int $width): Imagick
    {
        // Imagick is told what it is reading, never left to guess: only a
        // PNG, a WebP or a cleaned SVG reaches it.
        $format = match (true) {
            str_starts_with($logo, "\x89PNG\r\n\x1a\n") => 'png',
            str_starts_with($logo, 'RIFF') && substr($logo, 8, 4) === 'WEBP' => 'webp',
            str_contains(substr($logo, 0, 2048), '<svg') => 'svg',
            default => throw new InvalidArgumentException('Use a PNG or SVG logo with a transparent background.'),
        };

        if ($format === 'svg') {
            $logo = $this->cleanSvg($logo);
        }

        $mark = new Imagick();
        $mark->setBackgroundColor(new ImagickPixel('transparent'));

        try {
            if ($format === 'svg') {
                // Drawn large, so it is sharp at any card size.
                $mark->setResolution(600, 600);
            }

            $mark->setFormat($format);
            $mark->readImageBlob($logo, "logo.{$format}");
        } catch (\ImagickException) {
            throw new InvalidArgumentException('That logo could not be read. Use a PNG or SVG with a transparent background.');
        }

        $mark->setImageFormat('png32');
        $mark->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
        $mark->trimImage(0);
        $mark->setImagePage(0, 0, 0, 0);

        if ($mark->getImageWidth() > $width * 2) {
            $mark->resizeImage($width * 2, 0, Imagick::FILTER_LANCZOS, 1);
        }

        return $mark;
    }

    /**
     * An SVG can pull in other files, run scripts or define entities; a logo
     * needs none of that. Anything it refers to beyond itself is refused,
     * and what is left goes through the sanitiser Craft itself uses.
     */
    private function cleanSvg(string $svg): string
    {
        if (preg_match('/<!ENTITY|<!DOCTYPE|<script|<image|<foreignObject|<use[^>]+href\s*=\s*["\'](?!#)|xlink:href\s*=\s*["\'](?!#)|href\s*=\s*["\'](?!#)/i', $svg)) {
            throw new InvalidArgumentException('That SVG refers to other files or contains scripts. Export it as a plain SVG or a PNG.');
        }

        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true);
        $clean = $sanitizer->sanitize($svg);

        if (!is_string($clean) || !str_contains($clean, '<svg')) {
            throw new InvalidArgumentException('That SVG could not be read. Export it as a plain SVG or a PNG.');
        }

        return $clean;
    }

    /**
     * The colour the logo is mostly drawn in, ignoring what is see-through.
     * A logo that is white, black or grey gives a near-black ground.
     */
    private function mainColour(Imagick $mark): string
    {
        $sample = clone $mark;
        $sample->resizeImage(48, 48, Imagick::FILTER_BOX, 1, true);

        $counts = [];

        foreach ($sample->exportImagePixels(0, 0, $sample->getImageWidth(), $sample->getImageHeight(), 'RGBA', Imagick::PIXEL_CHAR) as $i => $value) {
            $pixel[$i % 4] = $value;

            if ($i % 4 !== 3 || $value < 200) {
                continue;
            }

            [$r, $g, $b] = $pixel;

            if (max($r, $g, $b) - min($r, $g, $b) < 24) {
                continue;
            }

            // Near shades count as one colour.
            $key = sprintf('#%02x%02x%02x', $r & 0xF0, $g & 0xF0, $b & 0xF0);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $exact[$key] ??= sprintf('#%02x%02x%02x', $r, $g, $b);
        }

        if ($counts === []) {
            return '#111111';
        }

        arsort($counts);

        return $exact[array_key_first($counts)];
    }

    private function hex(?string $colour): ?string
    {
        $colour = strtolower(trim((string) $colour));

        if ($colour === '') {
            return null;
        }

        if (preg_match('/^#?([0-9a-f]{3})$/', $colour, $m)) {
            $colour = $m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2];
        }

        if (!preg_match('/^#?([0-9a-f]{6})$/', $colour, $m)) {
            throw new InvalidArgumentException('Colours are written as hex, such as #ff2d20.');
        }

        return '#' . $m[1];
    }
}
