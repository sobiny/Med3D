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
        $sceneId = (int)Request::param('scene_id', 0);
        if ($sceneId <= 0) {
            return 'scene_id required';
        }

        $scene = Db::name('tv_scenes')
            ->where('id', $sceneId)
            ->whereNull('deleted_at')
            ->find();

        if (!$scene) {
            return 'Scene not found';
        }

        $models = Db::name('tv_models')
            ->where('scene_id', $sceneId)
            ->whereNull('deleted_at')
            ->order('sort_order asc,id asc')
            ->select()
            ->toArray();

        // 只把前端需要的字段传过去（减少暴露）
        $modelsForView = array_map(function ($m) {
            $key = ltrim(parse_url($m['file_path'], PHP_URL_PATH), '/');
            return [
                'id'            => (int)$m['id'],
                'display_name'  => (string)($m['display_name'] ?? ''),
                'file_path'     => $this->privateQiniuUrl($key),
                'file_type'     => strtolower((string)($m['file_type'] ?? '')),
                'mime'          => (string)($m['mime'] ?? ''),
                'file_size'     => isset($m['file_size_bytes']) ? (int)$m['file_size_bytes'] : null,
                'file_hash'     => (string)($m['file_hash'] ?? ''),
                'color_hex'     => (string)($m['color_hex'] ?? ''),
                'material_text' => (string)($m['material_text'] ?? ''),
                'info_json'     => (string)($m['info_json'] ?? ''),
            ];
        }, $models);

        return view('viewer/scene', [
            'scene'  => $scene,
            'models' => $modelsForView,
        ]);
    }
}
