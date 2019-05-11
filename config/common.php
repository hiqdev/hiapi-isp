<?php
/**
 * hiAPI ISP plugin
 *
 * @link      https://github.com/hiqdev/hiapi-isp
 * @package   hiapi-isp
 * @license   BSD-3-Clause
 * @copyright Copyright (c) 2017, HiQDev (http://hiqdev.com/)
 */

$definitions = [
    'ispTool' => [
        '__class' => \hiapi\isp\IspTool::class,
    ],
];

return class_exists('Yii') ? ['container' => ['definitions' => $definitions]] : $definitions;
