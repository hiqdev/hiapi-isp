<?php

namespace hiapi\isp\helpers;

class ErrorHelper
{
    public static function is(array $arr)
    {
        return array_key_exists('_error', $arr);
    }

    public static function not(array $arr)
    {
        return !array_key_exists('_error', $arr);
    }
}
