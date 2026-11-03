<?php

namespace App\Services\Demo;

use GdImage;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Paints soft, photo-like scenes with GD, so demo data ships no image assets and no
 * pictures of real people. The same seed always gives the same picture.
 */
class DemoImageFactory
{
    public const SCENES = ['sunset', 'beach', 'garden', 'party', 'snow', 'forest'];

    private const THUMBNAIL_MAX_DIM = 600;

    private const LQIP_MAX_DIM = 20;

    /**
     * @return array{original: string, thumbnail: string, lqip: string, width: int, height: int}
     */
    public function make(string $scene, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        [$width, $height] = $seed % 4 === 0 ? [1200, 1600] : [1600, 1200];

        $image = imagecreatetruecolor($width, $height);
        $this->{$scene}($image, $width, $height, $random);
        for ($pass = 0; $pass < 3; $pass++) {
            imagefilter($image, IMG_FILTER_GAUSSIAN_BLUR);
        }

        ob_start();
        imagejpeg($image, null, 85);
        $original = (string) ob_get_clean();

        return [
            'original' => $original,
            'thumbnail' => $this->webp($image, self::THUMBNAIL_MAX_DIM, 78),
            'lqip' => $this->webp($image, self::LQIP_MAX_DIM, 20),
            'width' => $width,
            'height' => $height,
        ];
    }

    private function webp(GdImage $image, int $maxDim, int $quality): string
    {
        $ratio = $maxDim / max(imagesx($image), imagesy($image));
        $width = max(1, (int) round(imagesx($image) * $ratio));
        $height = max(1, (int) round(imagesy($image) * $ratio));

        $scaled = imagecreatetruecolor($width, $height);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        ob_start();
        imagewebp($scaled, null, $quality);

        return (string) ob_get_clean();
    }

    private function sunset(GdImage $image, int $width, int $height, Randomizer $random): void
    {
        $horizon = (int) ($height * 0.62);
        $this->gradient($image, 0, $horizon, $width, [52, 58, 120], [250, 160, 110]);
        $this->circle($image, $random->getInt(200, $width - 200), $horizon - 60, 170, [255, 225, 160], 20);
        $this->hills($image, $width, $height, $horizon, [90, 70, 110], $random);
        $this->hills($image, $width, $height, $horizon + 150, [50, 50, 80], $random);
    }

    private function beach(GdImage $image, int $width, int $height, Randomizer $random): void
    {
        $sea = (int) ($height * 0.45);
        $sand = (int) ($height * 0.68);
        $this->gradient($image, 0, $sea, $width, [120, 190, 235], [215, 238, 248]);
        $this->gradient($image, $sea, $sand, $width, [40, 140, 180], [110, 205, 205]);
        $this->gradient($image, $sand, $height, $width, [240, 220, 175], [215, 185, 135]);
        $this->circle($image, $random->getInt(200, $width - 200), (int) ($sea * 0.3), 90, [255, 248, 215], 10);
        for ($umbrella = 0; $umbrella < 3; $umbrella++) {
            $this->circle(
                $image,
                $random->getInt(150, $width - 150),
                $random->getInt($sand + 40, $height - 150),
                $random->getInt(60, 110),
                $random->shuffleArray([[230, 90, 90], [250, 200, 80], [90, 140, 220]])[0],
            );
        }
    }

    private function garden(GdImage $image, int $width, int $height, Randomizer $random): void
    {
        $horizon = (int) ($height * 0.38);
        $this->gradient($image, 0, $horizon, $width, [150, 205, 240], [225, 240, 235]);
        $this->gradient($image, $horizon, $height, $width, [120, 180, 90], [60, 125, 65]);
        for ($flower = 0; $flower < 120; $flower++) {
            $y = $random->getInt($horizon + 30, $height);
            $this->circle(
                $image,
                $random->getInt(0, $width),
                $y,
                (int) (6 + 28 * ($y - $horizon) / ($height - $horizon)),
                $random->shuffleArray([[240, 110, 140], [250, 215, 90], [250, 250, 245], [190, 130, 230]])[0],
                10,
            );
        }
    }

    private function party(GdImage $image, int $width, int $height, Randomizer $random): void
    {
        $this->gradient($image, 0, $height, $width, [255, 225, 205], [245, 170, 170]);
        for ($balloon = 0; $balloon < 14; $balloon++) {
            $x = $random->getInt(60, $width - 60);
            $y = $random->getInt(80, (int) ($height * 0.72));
            $radius = $random->getInt(70, 130);
            $color = $random->shuffleArray([[230, 80, 100], [80, 150, 225], [250, 200, 70], [120, 200, 150], [175, 120, 220]])[0];
            imagesetthickness($image, 3);
            imageline($image, $x, $y + $radius, $x + $random->getInt(-40, 40), $height, $this->color($image, [120, 90, 90], 40));
            $this->circle($image, $x, $y, $radius, $color, 12);
            $this->circle($image, $x - (int) ($radius * 0.3), $y - (int) ($radius * 0.3), (int) ($radius * 0.2), [255, 255, 255], 70);
        }
    }

    private function snow(GdImage $image, int $width, int $height, Randomizer $random): void
    {
        $horizon = (int) ($height * 0.6);
        $this->gradient($image, 0, $horizon, $width, [140, 170, 205], [225, 232, 240]);
        $this->hills($image, $width, $height, $horizon, [245, 248, 252], $random);
        for ($tree = 0; $tree < 10; $tree++) {
            $x = $random->getInt(0, $width);
            $base = $random->getInt($horizon + 40, $height - 60);
            $size = $random->getInt(70, 150);
            imagefilledpolygon($image, [$x, $base - 3 * $size, $x - $size, $base, $x + $size, $base], $this->color($image, [45, 85, 80]));
        }
        for ($flake = 0; $flake < 260; $flake++) {
            $this->circle($image, $random->getInt(0, $width), $random->getInt(0, $height), $random->getInt(3, 11), [255, 255, 255], 30);
        }
    }

    private function forest(GdImage $image, int $width, int $height, Randomizer $random): void
    {
        $this->gradient($image, 0, $height, $width, [235, 215, 150], [165, 105, 60]);
        for ($leaf = 0; $leaf < 90; $leaf++) {
            $this->circle(
                $image,
                $random->getInt(0, $width),
                $random->getInt(0, (int) ($height * 0.55)),
                $random->getInt(40, 120),
                $random->shuffleArray([[215, 120, 50], [225, 165, 60], [185, 75, 45], [150, 150, 60]])[0],
                55,
            );
        }
        for ($trunk = 0; $trunk < 7; $trunk++) {
            $x = $random->getInt(0, $width);
            $half = $random->getInt(18, 48);
            imagefilledrectangle($image, $x - $half, 0, $x + $half, $height, $this->color($image, [75, 50, 40], 15));
        }
    }

    /**
     * @param  array{int, int, int}  $top
     * @param  array{int, int, int}  $bottom
     */
    private function gradient(GdImage $image, int $from, int $to, int $width, array $top, array $bottom): void
    {
        for ($y = $from; $y < $to; $y++) {
            $mix = ($y - $from) / max(1, $to - $from);
            $color = imagecolorallocate(
                $image,
                (int) ($top[0] + ($bottom[0] - $top[0]) * $mix),
                (int) ($top[1] + ($bottom[1] - $top[1]) * $mix),
                (int) ($top[2] + ($bottom[2] - $top[2]) * $mix),
            );
            imageline($image, 0, $y, $width, $y, $color);
        }
    }

    /**
     * @param  array{int, int, int}  $rgb
     */
    private function hills(GdImage $image, int $width, int $height, int $baseline, array $rgb, Randomizer $random): void
    {
        $color = $this->color($image, $rgb);
        $phase = $random->getFloat(0, 6);
        $wave = $random->getFloat(0.002, 0.005);
        for ($x = 0; $x < $width; $x += 2) {
            $y = (int) ($baseline + 60 * sin($x * $wave + $phase) + 25 * sin($x * $wave * 3 + $phase));
            imagefilledrectangle($image, $x, $y, $x + 1, $height, $color);
        }
    }

    /**
     * @param  array{int, int, int}  $rgb
     */
    private function circle(GdImage $image, int $x, int $y, int $radius, array $rgb, int $alpha = 0): void
    {
        imagefilledellipse($image, $x, $y, $radius * 2, $radius * 2, $this->color($image, $rgb, $alpha));
    }

    /**
     * @param  array{int, int, int}  $rgb
     */
    private function color(GdImage $image, array $rgb, int $alpha = 0): int
    {
        return (int) imagecolorallocatealpha($image, $rgb[0], $rgb[1], $rgb[2], $alpha);
    }
}
