<?php
class Image {
    private $file;
    private $image;
    private $width;
    private $height;
    private $bits;
    private $mime;
    private $info;

    public function __construct($file) {
        $registry = Registry::getInstance();
        if (file_exists($file)) {
            $this->file = $file;

            // If, there is no file, or file is with size 0, or any other error,
            // this will not generate error now, but it will be logged.
            ob_start();
            $info = getimagesize($file);
            $resize_warning = ob_get_clean();
            if($resize_warning) {
                $registry->log->write("Cannot resize system/library/image: $resize_warning");
            }

            /* OC1 methods compatibility start */
            $this->info = array(
                'width'  => $info[0],
                'height' => $info[1],
                'bits'   => $info['bits'],
                'mime'   => $info['mime']
            );
            /* OC1 methods compatibility end */

            $this->width = $info[0];
            $this->height = $info[1];
            $this->bits = isset($info['bits']) ? $info['bits'] : '';
            $this->mime = isset($info['mime']) ? $info['mime'] : '';

            if ($this->mime == 'image/gif') {
                $this->image = imagecreatefromgif($file);
            } elseif ($this->mime == 'image/png') {
                $this->image = imagecreatefrompng($file);
            } elseif ($this->mime == 'image/jpeg') {
                $this->image = imagecreatefromjpeg($file);
            } else {
                throw new RuntimeException('Could not found image mime' . $file . '!');
            }
        } else {
            $registry->log->write('Error: Could not load image ' . $file . '!');
        }
    }

    public function getFile() {
        return $this->file;
    }

    public function getImage() {
        return $this->image;
    }

    public function getWidth() {
        return $this->width;
    }

    public function getHeight() {
        return $this->height;
    }

    public function getBits() {
        return $this->bits;
    }

    public function getMime() {
        return $this->mime;
    }

    public function save($file, $quality = 100) {
        $info = pathinfo($file);

        $extension = strtolower($info['extension']);

        if (is_resource($this->image)) {
            if ($extension == 'jpeg' || $extension == 'jpg') {
                imagejpeg($this->image, $file, $quality);
            } elseif ($extension == 'png') {
                imagepng($this->image, $file);
            } elseif ($extension == 'gif') {
                imagegif($this->image, $file);
            }

            imagedestroy($this->image);
        }
    }

    public function resize($width = 0, $height = 0, $default = '') {

        if (!$this->width || !$this->height) {
            return;
        }
        !$width > 0 ? $width = $this->width : false;
        !$height > 0 ? $height = $this->height : false;


        $xpos = 0;
        $ypos = 0;
        $scale = 1;

        $scale_w = $width / $this->width;
        $scale_h = $height / $this->height;

        if ($default == 'w') {
            $scale = $scale_w;
        } elseif ($default == 'h') {
            $scale = $scale_h;
        } else {
            $scale = min($scale_w, $scale_h);
        }

        if ($scale == 1 && $scale_h == $scale_w && $this->mime != 'image/png') {
            return;
        }

        $new_width = (int)($this->width * $scale);
        $new_height = (int)($this->height * $scale);
        $xpos = (int)(($width - $new_width) / 2);
        $ypos = (int)(($height - $new_height) / 2);

        $image_old = $this->image;
        $this->image = imagecreatetruecolor($width, $height);

        if ($this->mime == 'image/png') {
            imagealphablending($this->image, false);
            imagesavealpha($this->image, true);
            $background = imagecolorallocatealpha($this->image, 255, 255, 255, 127);
            imagecolortransparent($this->image, $background);
        } else {
            $background = imagecolorallocate($this->image, 255, 255, 255);
        }

        imagefilledrectangle($this->image, 0, 0, $width, $height, $background);

        imagecopyresampled($this->image, $image_old, $xpos, $ypos, 0, 0, $new_width, $new_height, $this->width, $this->height);
        imagedestroy($image_old);

        $this->width = $width;
        $this->height = $height;
    }

    public function watermark($watermark, $position = 'bottomright') {
        switch ($position) {
            case 'topleft':
                $watermark_pos_x = 0;
                $watermark_pos_y = 0;
                break;
            case 'topcenter':
                $watermark_pos_x = intval(($this->width - $watermark->getWidth()) / 2);
                $watermark_pos_y = 0;
                break;
            case 'topright':
                $watermark_pos_x = $this->width - $watermark->getWidth();
                $watermark_pos_y = 0;
                break;
            case 'middleleft':
                $watermark_pos_x = 0;
                $watermark_pos_y = intval(($this->height - $watermark->getHeight()) / 2);
                break;
            case 'middlecenter':
                $watermark_pos_x = intval(($this->width - $watermark->getWidth()) / 2);
                $watermark_pos_y = intval(($this->height - $watermark->getHeight()) / 2);
                break;
            case 'middleright':
                $watermark_pos_x = $this->width - $watermark->getWidth();
                $watermark_pos_y = intval(($this->height - $watermark->getHeight()) / 2);
                break;
            case 'bottomleft':
                $watermark_pos_x = 0;
                $watermark_pos_y = $this->height - $watermark->getHeight();
                break;
            case 'bottomcenter':
                $watermark_pos_x = intval(($this->width - $watermark->getWidth()) / 2);
                $watermark_pos_y = $this->height - $watermark->getHeight();
                break;
            case 'bottomright':
                $watermark_pos_x = $this->width - $watermark->getWidth();
                $watermark_pos_y = $this->height - $watermark->getHeight();
                break;
        }

        imagealphablending($this->image, true);
        imagesavealpha($this->image, true);
        imagecopy($this->image, $watermark->getImage(), $watermark_pos_x, $watermark_pos_y, 0, 0, $watermark->getWidth(), $watermark->getHeight());

        imagedestroy($watermark->getImage());
    }

    public function crop($top_x, $top_y, $bottom_x, $bottom_y) {
        $image_old = $this->image;
        $this->image = imagecreatetruecolor($bottom_x - $top_x, $bottom_y - $top_y);

        imagecopy($this->image, $image_old, 0, 0, $top_x, $top_y, $this->width, $this->height);
        imagedestroy($image_old);

        $this->width = $bottom_x - $top_x;
        $this->height = $bottom_y - $top_y;
    }

    public function rotate($degree, $color = 'FFFFFF') {
        $rgb = $this->html2rgb($color);

        $this->image = imagerotate($this->image, $degree, imagecolorallocate($this->image, $rgb[0], $rgb[1], $rgb[2]));

        $this->width = imagesx($this->image);
        $this->height = imagesy($this->image);
    }

    private function filter() {
        $args = func_get_args();

        call_user_func_array('imagefilter', $args);
    }

    private function text($text, $x = 0, $y = 0, $size = 5, $color = '000000') {
        $rgb = $this->html2rgb($color);

        imagestring($this->image, $size, $x, $y, $text, imagecolorallocate($this->image, $rgb[0], $rgb[1], $rgb[2]));
    }

    private function merge($merge, $x = 0, $y = 0, $opacity = 100) {
        imagecopymerge($this->image, $merge->getImage(), $x, $y, 0, 0, $merge->getWidth(), $merge->getHeight(), $opacity);
    }

    private function html2rgb($color) {
        if ($color[0] == '#') {
            $color = substr($color, 1);
        }

        if (strlen($color) == 6) {
            list($r, $g, $b) = array( $color[0] . $color[1], $color[2] . $color[3],
                $color[4] . $color[5] );
        } elseif (strlen($color) == 3) {
            list($r, $g, $b) = array( $color[0] . $color[0], $color[1] . $color[1],
                $color[2] . $color[2] );
        } else {
            return false;
        }

        $r = hexdec($r);
        $g = hexdec($g);
        $b = hexdec($b);

        return array( $r, $g, $b );
    }

    /*
     * Jerome Bohg - 05 juli 2011
     *  custom functie gemaakt om uitsnedes te maken
     *  voor images ipv de hele foto weer te geven met
     *  witrumte eromheen
     * */

    public function onesize($maxsize = 0) {

        if (!$this->info['width'] || !$this->info['height']) {
            return;
        }

        //afmetingen bepalen
        $photo_width = $this->info['width'];
        $photo_height = $this->info['height'];


        // calculate dimensions
        if ($photo_width > $maxsize OR $photo_height > $maxsize) {

            if ($photo_width == $photo_height) {

                $width = $maxsize;
                $height = $maxsize;
            } elseif ($photo_width > $photo_height) {

                $scale = $photo_width / $maxsize;
                $width = $maxsize;
                $height = round($photo_height / $scale);
            } else {

                $scale = $photo_height / $maxsize;
                $height = $maxsize;
                $width = round($photo_width / $scale);
            }
        } else {

            $width = $photo_width;
            $height = $photo_height;
        }

        // and bring it all to live
        $image_old = $this->image;
        $this->image = imagecreatetruecolor($width, $height);

        if (isset($this->info['mime']) && $this->info['mime'] == 'image/png') {
            imagealphablending($this->image, false);
            imagesavealpha($this->image, true);
            $background = imagecolorallocatealpha($this->image, 255, 255, 255, 127);
            imagecolortransparent($this->image, $background);
        } else {
            $background = imagecolorallocate($this->image, 255, 255, 255);
        }

        imagefilledrectangle($this->image, 0, 0, $width, $height, $background);


        imagecopyresampled($this->image, $image_old, 0, 0, 0, 0, $width, $height, $photo_width, $photo_height);
        imagedestroy($image_old);

        $this->info['width'] = $width;
        $this->info['height'] = $height;
    }

    /*
     * Jerome Bohg - 05 juli 2011
     *  custom functie gemaakt om uitsnedes te maken
     *  voor images ipv de hele foto weer te geven met
     *  witrumte eromheen
     * */

    public function cropsize($width = 0, $height = 0) {

        if (!$this->info['width'] || !$this->info['height']) {
            return;
        }
        !$width > 0 ? $width = $this->width : false;
        !$height > 0 ? $height = $this->height : false;

        //afmetingen bepalen
        $photo_width = $this->info['width'];
        $photo_height = $this->info['height'];

        $new_width = $width;
        $new_height = $height;


        //als foto te hoog is
        if (($photo_width / $new_width) < ($photo_height / $new_height)) {

            $from_y = ceil(($photo_height - ($new_height * $photo_width / $new_width)) / 2);
            $from_x = '0';
            $photo_y = ceil(($new_height * $photo_width / $new_width));
            $photo_x = $photo_width;
        }

        //als foto te breed is
        if (($photo_height / $new_height) < ($photo_width / $new_width)) {

            $from_x = ceil(($photo_width - ($new_width * $photo_height / $new_height)) / 2);
            $from_y = '0';
            $photo_x = ceil(($new_width * $photo_height / $new_height));
            $photo_y = $photo_height;
        }

        //als verhoudingen gelijk zijn
        if (($photo_width / $new_width) == ($photo_height / $new_height)) {

            $from_x = ceil(($photo_width - ($new_width * $photo_height / $new_height)) / 2);
            $from_y = '0';
            $photo_x = ceil(($new_width * $photo_height / $new_height));
            $photo_y = $photo_height;
        }


        $image_old = $this->image;
        $this->image = imagecreatetruecolor($width, $height);

        if (isset($this->info['mime']) && $this->info['mime'] == 'image/png') {
            imagealphablending($this->image, false);
            imagesavealpha($this->image, true);
            $background = imagecolorallocatealpha($this->image, 255, 255, 255, 127);
            imagecolortransparent($this->image, $background);
        } else {
            $background = imagecolorallocate($this->image, 255, 255, 255);
        }

        imagefilledrectangle($this->image, 0, 0, $width, $height, $background);

        imagecopyresampled($this->image, $image_old, 0, 0, $from_x, $from_y, $new_width, $new_height, $photo_x, $photo_y);
        imagedestroy($image_old);

        $this->info['width'] = $width;
        $this->info['height'] = $height;
    }

    /* resaizopēc lielākās iepsējāmās izmēra, BEZ baltajām malām! */

    public function propsize($width = 0, $height = 0, $default = '') {
        if (!$this->info['width'] || !$this->info['height']) {
            return;
        }
        !$width > 0 ? $width = $this->width : false;
        !$height > 0 ? $height = $this->height : false;

        $xpos = 0;
        $ypos = 0;
        $scale = 1;

        $scale_w = $width / $this->info['width'];
        $scale_h = $height / $this->info['height'];

        if ($default == 'w') {
            $scale = $scale_w;
        } elseif ($default == 'h') {
            $scale = $scale_h;
        } else {
            $scale = min($scale_w, $scale_h);
        }

        if ($scale == 1 && $scale_h == $scale_w && $this->info['mime'] != 'image/png') {
            return;
        }

        $new_width = (int)($this->info['width'] * $scale);
        $new_height = (int)($this->info['height'] * $scale);
        $xpos = (int)(($width - $new_width) / 2);
        $ypos = (int)(($height - $new_height) / 2);

        $image_old = $this->image;
        $this->image = imagecreatetruecolor($new_width, $new_height);

        if (isset($this->info['mime']) && $this->info['mime'] == 'image/png') {
            imagealphablending($this->image, false);
            imagesavealpha($this->image, true);
            $background = imagecolorallocatealpha($this->image, 255, 255, 255, 127);
            imagecolortransparent($this->image, $background);
        } else {
            $background = imagecolorallocate($this->image, 255, 255, 255);
        }


        imagefilledrectangle($this->image, 0, 0, $new_width, $new_height, $background);

        imagecopyresampled($this->image, $image_old, 0, 0, 0, 0, $new_width, $new_height, $this->info['width'], $this->info['height']);

        imagedestroy($image_old);

        $this->info['width'] = $width;
        $this->info['height'] = $height;
    }

    /* Resizing only down, if any of original width is biiger then sizes */

    public function downsize($width = 0, $height = 0, $default = '') {
        if (!$this->info['width'] || !$this->info['height']) {
            return;
        }
        !$width > 0 ? $width = $this->width : false;
        !$height > 0 ? $height = $this->height : false;

        $xpos = 0;
        $ypos = 0;
        $scale = 1;

        if ($this->info['width'] < $width && $this->info['height'] < $height) {
            $width = $this->info['width'];
            $height = $this->info['height'];
        };

        //pr( $this->info['width']  );



        $scale_w = $width / $this->info['width'];
        $scale_h = $height / $this->info['height'];

        if ($default == 'w') {
            $scale = $scale_w;
        } elseif ($default == 'h') {
            $scale = $scale_h;
        } else {
            $scale = min($scale_w, $scale_h);
        }

        if ($scale == 1 && $scale_h == $scale_w && $this->info['mime'] != 'image/png') {
            return;
        }

        $new_width = (int)($this->info['width'] * $scale);
        $new_height = (int)($this->info['height'] * $scale);
        $xpos = (int)(($width - $new_width) / 2);
        $ypos = (int)(($height - $new_height) / 2);

        $image_old = $this->image;
        $this->image = imagecreatetruecolor($new_width, $new_height);

        if (isset($this->info['mime']) && $this->info['mime'] == 'image/png') {
            imagealphablending($this->image, false);
            imagesavealpha($this->image, true);
            $background = imagecolorallocatealpha($this->image, 255, 255, 255, 127);
            imagecolortransparent($this->image, $background);
        } else {
            $background = imagecolorallocate($this->image, 255, 255, 255);
        }


        imagefilledrectangle($this->image, 0, 0, $new_width, $new_height, $background);

        imagecopyresampled($this->image, $image_old, 0, 0, 0, 0, $new_width, $new_height, $this->info['width'], $this->info['height']);

        imagedestroy($image_old);

        $this->info['width'] = $width;
        $this->info['height'] = $height;
    }

    public function addwatermark($position = 'bottomright') {

        if (!file_exists(DIR_IMAGE . 'watermark.png')) {
            imagedestroy($this->image);
            error_log('Function addwatermark() error: folder image/ does not have hardcoded predefined image file: watermark.png');
            return false;
        }

        $width = imagesx($this->image);
        $height = imagesy($this->image);

        $watermark = imagecreatefrompng(DIR_IMAGE . 'watermark.png'); //TODO: add to Config!
        imageAlphaBlending($watermark, false);
        imageSaveAlpha($watermark, true);

        $watermark_width = imagesx($watermark);
        $watermark_height = imagesy($watermark);

        $dest_width = $width / 2;
        $dest_height = $width / 2;

        switch ($position) {
            case 'topleft':
                $watermark_pos_x = 0;
                $watermark_pos_y = 0;
                break;
            case 'topright':
                $watermark_pos_x = $width - $watermark_width;
                $watermark_pos_y = 0;
                break;
            case 'bottomleft':
                $watermark_pos_x = 0;
                $watermark_pos_y = $height - $watermark_height;
                break;
            case 'bottomright':
                $watermark_pos_x = $width - $watermark_width;
                $watermark_pos_y = $height - $watermark_height;
                break;
            case 'middle':
                $watermark_pos_x = ($width - $dest_width) / 2;
                $watermark_pos_y = ($height - $dest_height) / 2;
                break;
        }

        $slate = imagecreatetruecolor($width, $height);
        $transparent = imagecolorallocatealpha($slate, 0, 255, 0, 127);
        imagefill($slate, 0, 0, $transparent);

        // now do the copying
        imagecopy($slate, $this->image, 0, 0, 0, 0, $width, $height);
        imagecopyresampled($slate, $watermark, $watermark_pos_x, $watermark_pos_y, 0, 0, $dest_width, $dest_height, $watermark_width, $watermark_height);
        imageAlphaBlending($slate, false);
        imageSaveAlpha($slate, true);
        imagealphablending($watermark, true);
        imagecopyresampled($this->image, $watermark, $watermark_pos_x, $watermark_pos_y, 0, 0, $dest_width, $dest_height, $watermark_width, $watermark_height);
        $this->image = $slate;
        imagedestroy($watermark);
    }

}