<?php

namespace App\Ffmpeg;

class ToneMap
{
    public static function filter(string $pixelFormat): string
    {
        return 'zscale=t=linear:npl=100,format=gbrpf32le,zscale=p=bt709,tonemap=tonemap=hable:desat=0,zscale=t=bt709:m=bt709:r=tv,format='.$pixelFormat
            .',sidedata=mode=delete:type=MASTERING_DISPLAY_METADATA,sidedata=mode=delete:type=CONTENT_LIGHT_LEVEL'
            .',sidedata=mode=delete:type=DYNAMIC_HDR_PLUS,sidedata=mode=delete:type=DOVI_RPU_BUFFER,sidedata=mode=delete:type=DOVI_METADATA,sidedata=mode=delete:type=DYNAMIC_HDR_VIVID';
    }
}
