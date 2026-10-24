<?php

namespace App\Encoding\Enums;

enum EncoderType: string
{
    case VIDEO = 'video';
    case AUDIO = 'audio';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
