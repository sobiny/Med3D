<?php
declare(strict_types=1);

namespace app\tv\controller;
use Qiniu\Auth as QiniuAuth;
use think\facade\Db;
use think\facade\Request;

class Viewer
{
    private function privateQiniuUrl(string $key): string
    {
        $cfg = config('qiniu');
        $auth = new QiniuAuth($cfg['ak'], $cfg['sk']);

        $baseUrl = rtrim($cfg['domain'], '/') . '/' . ltrim($key, '/');
        $expires = time() + ($cfg['expire'] ?? 1800);

        return $auth->privateDownloadUrl($baseUrl, $expires);
    }
    /**
     * GET /tv/viewer/scene?scene_id=123
     */
    public function scene()
    {
        $html = file_get_contents(app_path() . 'view/viewer/scene.html');
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
