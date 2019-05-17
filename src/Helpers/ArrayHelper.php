<?php

namespace hiapi\isp\helpers;

class ArrayHelper
{
    /**
     * @param array $arr
     * @param $delimiter
     * @return string
     */
    static function join(array $arr, $delimiter): string
    {
        return implode($delimiter, $arr);
    }

    /**
     * @param string $arr
     * @param $delimiter
     * @return array
     */
    static function split(string $arr, $delimiter): array
    {
        $res = [];
        foreach (explode($delimiter, $arr) as $value) {
            $value = trim($value);
            if (strlen($value)) {
                $res[] = $value;
            }
        };

        return $res;
    }

    /**
     * @param array $arr
     * @param array $keys
     * @return array
     */
    static function extract(array $arr, array $keys): array
    {
        $res = [];
        foreach ($keys as $key) {
            $value = $arr[$key] ?? null;
            if (!is_null($value)) {
                $res[$key] = $arr[$key];
            }
        }

        return $res;
    }

    /**
     * @param array $arr
     * @param string $key
     * @return mixed|null
     */
    static function get(array $arr, string $key)
    {
        return $arr[$key] ?? null;
    }

    /**
     * @param array $arr
     * @param string $key
     * @return bool
     */
    static function has(array $arr, string $key): bool
    {
        return in_array($key, $arr);
    }
}
