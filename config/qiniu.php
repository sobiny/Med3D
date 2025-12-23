<?php
return [
    'ak'     => env('qiniu.ak'),
    'sk'     => env('qiniu.sk'),
    'domain' => env('qiniu.domain'), // 访问域名
    'expire' => 1800, // 秒，30分钟
];
