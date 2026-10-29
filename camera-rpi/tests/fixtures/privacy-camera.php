#!/usr/bin/php
<?php
// Real JPEG test generator, no device access. Only invoked from isolated tests.
$index=array_search('--filename',$argv,true);
if ($index===false || !isset($argv[$index+1])) exit(2);
file_put_contents(getenv('PRIVACY_CAMERA_LOG'),"capture\n",FILE_APPEND);
$image=imagecreatetruecolor(1024,512);
$gray=(int)(getenv('PRIVACY_CAMERA_GRAY') ?: 0);
imagefill($image,0,0,imagecolorallocate($image,$gray,$gray,$gray));
imagejpeg($image,$argv[$index+1],95);
imagedestroy($image);
