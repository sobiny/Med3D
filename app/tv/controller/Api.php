<?php
declare(strict_types=1);

namespace app\tv\controller;

use Qiniu\Auth as QiniuAuth;
use think\facade\Db;
use think\facade\Request;
use think\response\Json;

class Api
{
    // ==========
    // 工具方法
    // ==========

    private function ok(array $data = [], string $msg = 'ok'): Json
    {
        return json([
            'code' => 0,
            'msg'  => $msg,
            'data' => $data,
        ]);
    }

    private function fail(string $msg, int $code = 1, array $data = []): Json
    {
        return json([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
        ]);
    }

    private function requirePost(): ?Json
    {
        if (!Request::isPost()) {
            return $this->fail('Method Not Allowed, use POST', 405);
        }
        return null;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * 简单取参：优先 JSON body，其次 form-data / x-www-form-urlencoded
     */
    private function input(): array
    {
        $json = Request::getInput(); // 原始 body
        $data = [];
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) $data = $decoded;
        }
        if (!$data) {
            $data = Request::post();
        }
        return is_array($data) ? $data : [];
    }

    // =========================
    // 1) 患者：新增/更新（Upsert）
    // =========================

    /**
     * POST /tv/api/patientUpsert
     * body(JSON or form):
     * {
     *   "patient_uid": "P20250101-0001",   // 可选，但建议传（系统内ID）
     *   "name": "张三",
     *   "sex": "M",
     *   "birth_date": "1980-01-02",
     *   "id_number": "xxxxxx",            // 关键：存在且非空则查重，不新增
     *   "phone": "xxx",
     *   "notes": "..."
     * }
     */
    public function patientUpsert(): Json
    {
        if ($r = $this->requirePost()) return $r;
        $in = $this->input();

        $idNumber = isset($in['id_number']) ? trim((string)$in['id_number']) : '';
        $patientUid = isset($in['patient_uid']) ? trim((string)$in['patient_uid']) : '';

        $payload = [
            'patient_uid' => $patientUid,
            'name'        => $in['name']        ?? null,
            'sex'         => $in['sex']         ?? null,
            'birth_date'  => $in['birth_date']  ?? null,
            'id_number'   => $idNumber !== '' ? $idNumber : null,
            'phone'       => $in['phone']       ?? null,
            'notes'       => $in['notes']       ?? null,
            'updated_at'  => $this->now(),
        ];

        // 如果 patient_uid 为空，给一个自动的（你也可以改成 UUID）
        if ($payload['patient_uid'] === '' || $payload['patient_uid'] === null) {
            $payload['patient_uid'] = 'P' . date('YmdHis') . '-' . random_int(1000, 9999);
        }

        try {
            $ret = Db::transaction(function () use ($idNumber, $payload, $in) {
                // 规则：如果 id_number 存在且非空 -> 查重，不新增
                if ($idNumber !== '') {
                    $exists = Db::name('tv_patients')
                        ->whereNull('deleted_at')
                        ->where('id_number', $idNumber)
                        ->find();

                    if ($exists) {
                        // 如果用户明确传了 id（或 patient_uid），也允许更新现有记录
                        $allowUpdate = !empty($in['id']) || !empty($in['patient_uid']);
                        if ($allowUpdate) {
                            Db::name('tv_patients')->where('id', $exists['id'])->update($payload);
                            $exists = Db::name('tv_patients')->where('id', $exists['id'])->find();
                            return ['action' => 'updated_by_id_number', 'patient' => $exists];
                        }
                        return ['action' => 'exists', 'patient' => $exists];
                    }

                    // 不存在 -> 新增
                    $payload['created_at'] = $this->now();
                    $newId = Db::name('tv_patients')->insertGetId($payload);
                    $row = Db::name('tv_patients')->where('id', $newId)->find();
                    return ['action' => 'created', 'patient' => $row];
                }

                // id_number 为空/未传：走“按 id 或 patient_uid 更新，否则新增”
                if (!empty($in['id'])) {
                    $id = (int)$in['id'];
                    $row = Db::name('tv_patients')->where('id', $id)->whereNull('deleted_at')->find();
                    if (!$row) {
                        throw new \RuntimeException('patient id not found');
                    }
                    Db::name('tv_patients')->where('id', $id)->update($payload);
                    $row = Db::name('tv_patients')->where('id', $id)->find();
                    return ['action' => 'updated_by_id', 'patient' => $row];
                }

                if (!empty($payload['patient_uid'])) {
                    $row = Db::name('tv_patients')
                        ->whereNull('deleted_at')
                        ->where('patient_uid', $payload['patient_uid'])
                        ->find();

                    if ($row) {
                        Db::name('tv_patients')->where('id', $row['id'])->update($payload);
                        $row = Db::name('tv_patients')->where('id', $row['id'])->find();
                        return ['action' => 'updated_by_patient_uid', 'patient' => $row];
                    }
                }

                // 新增
                $payload['created_at'] = $this->now();
                $newId = Db::name('tv_patients')->insertGetId($payload);
                $row = Db::name('tv_patients')->where('id', $newId)->find();
                return ['action' => 'created_no_id_number', 'patient' => $row];
            });

            return $this->ok($ret);
        } catch (\Throwable $e) {
            return $this->fail('patientUpsert failed: ' . $e->getMessage(), 500);
        }
    }

    // ===================
    // 2) 场景：新增
    // ===================

    /**
     * POST /tv/api/sceneCreate
     * {
     *   "patient_id": 1,              // 必填
     *   "scene_uid": "S-xxxx",        // 可选
     *   "title": "xxx",               // 必填
     *   "imaging_number": "xxx",
     *   "imaging_date": "2025-12-01",
     *   "recon_date": "2025-12-02",
     *   "accession_number": "...",
     *   "study_uid": "...",
     *   "series_uid": "...",
     *   "modality": "CT",
     *   "tags": "cspine,preop"
     * }
     */
    public function sceneCreate(): Json
    {
        if ($r = $this->requirePost()) return $r;
        $in = $this->input();

        $patientId = isset($in['patient_id']) ? (int)$in['patient_id'] : 0;
        $title = isset($in['title']) ? trim((string)$in['title']) : '';

        if ($patientId <= 0) return $this->fail('patient_id required', 422);
        if ($title === '') return $this->fail('title required', 422);

        try {
            $ret = Db::transaction(function () use ($in, $patientId, $title) {
                $p = Db::name('tv_patients')->where('id', $patientId)->whereNull('deleted_at')->find();
                if (!$p) throw new \RuntimeException('patient not found');

                $sceneUid = isset($in['scene_uid']) ? trim((string)$in['scene_uid']) : '';
                if ($sceneUid === '') {
                    $sceneUid = 'S' . date('YmdHis') . '-' . random_int(1000, 9999);
                }

                $row = [
                    'patient_id'       => $patientId,
                    'scene_uid'        => $sceneUid,
                    'title'            => $title,
                    'imaging_number'   => $in['imaging_number']   ?? null,
                    'imaging_date'     => $in['imaging_date']     ?? null,
                    'recon_date'       => $in['recon_date']       ?? null,
                    'accession_number' => $in['accession_number'] ?? null,
                    'study_uid'        => $in['study_uid']        ?? null,
                    'series_uid'       => $in['series_uid']       ?? null,
                    'modality'         => $in['modality']         ?? null,
                    'status'           => 1,
                    'tags'             => $in['tags']             ?? null,
                    'created_at'       => $this->now(),
                    'updated_at'       => $this->now(),
                ];

                $id = Db::name('tv_scenes')->insertGetId($row);
                $scene = Db::name('tv_scenes')->where('id', $id)->find();
                return ['scene' => $scene];
            });

            return $this->ok($ret, 'scene created');
        } catch (\Throwable $e) {
            return $this->fail('sceneCreate failed: ' . $e->getMessage(), 500);
        }
    }

    // ===================
    // 3) 三维模型：新增
    // ===================

    /**
     * POST /tv/api/modelCreate
     * {
     *   "scene_id": 10,                 // 必填
     *   "display_name": "C5.stl",       // 必填
     *   "file_path": "/data/xxx.stl",   // 必填
     *   "file_type": "stl",             // 必填
     *   "mime": "model/stl",
     *   "file_size_bytes": 12345,
     *   "file_hash": "sha256...",
     *   "color_hex": "#D9DEE7",
     *   "material_text": "PLA-White",
     *   "info_json": "{\"volume_mm3\":123}",
     *   "category": "bone",
     *   "sort_order": 0
     * }
     */
    public function modelCreate(): Json
    {
        if ($r = $this->requirePost()) return $r;
        $in = $this->input();

        $sceneId = isset($in['scene_id']) ? (int)$in['scene_id'] : 0;
        if ($sceneId <= 0) return $this->fail('scene_id required', 422);

        $displayName = isset($in['display_name']) ? trim((string)$in['display_name']) : '';
        $filePath = isset($in['file_path']) ? trim((string)$in['file_path']) : '';
        $fileType = isset($in['file_type']) ? trim((string)$in['file_type']) : '';

        if ($displayName === '') return $this->fail('display_name required', 422);
        if ($filePath === '') return $this->fail('file_path required', 422);
        if ($fileType === '') return $this->fail('file_type required', 422);

        try {
            $ret = Db::transaction(function () use ($in, $sceneId, $displayName, $filePath, $fileType) {
                $scene = Db::name('tv_scenes')->where('id', $sceneId)->whereNull('deleted_at')->find();
                if (!$scene) throw new \RuntimeException('scene not found');

                $row = [
                    'scene_id'        => $sceneId,
                    'display_name'    => $displayName,
                    'file_path'       => $filePath,
                    'file_type'       => $fileType,
                    'mime'            => $in['mime'] ?? null,
                    'file_size_bytes' => isset($in['file_size_bytes']) ? (int)$in['file_size_bytes'] : null,
                    'file_hash'       => $in['file_hash'] ?? null,
                    'color_hex'       => $in['color_hex'] ?? null,
                    'material_text'   => $in['material_text'] ?? null,
                    'info_json'       => $in['info_json'] ?? null, // MariaDB JSON 不稳定：存字符串即可
                    'category'        => $in['category'] ?? null,
                    'sort_order'      => isset($in['sort_order']) ? (int)$in['sort_order'] : 0,
                    'created_at'      => $this->now(),
                    'updated_at'      => $this->now(),
                ];

                $id = Db::name('tv_models')->insertGetId($row);
                $model = Db::name('tv_models')->where('id', $id)->find();
                return ['model' => $model];
            });

            return $this->ok($ret, 'model created');
        } catch (\Throwable $e) {
            return $this->fail('modelCreate failed: ' . $e->getMessage(), 500);
        }
    }

    // ===================
    // 4) 影像：新增
    // ===================

    /**
     * POST /tv/api/imageCreate
     * {
     *   "scene_id": 10,                    // 必填
     *   "display_name": "DICOM",
     *   "file_path": "/data/dicom.zip",    // 必填
     *   "file_type": "dicom|zip|nifti",    // 必填
     *   "mime": "application/zip",
     *   "file_size_bytes": 1234567,
     *   "file_hash": "sha256...",
     *   "slice_count": 302,
     *   "study_uid": "...",
     *   "series_uid": "...",
     *   "modality": "CT",
     *   "institution": "xxx",
     *   "spacing_x_mm": 1.0,
     *   "spacing_y_mm": 1.0,
     *   "spacing_z_mm": 3.27
     * }
     */
    public function imageCreate(): Json
    {
        if ($r = $this->requirePost()) return $r;
        $in = $this->input();

        $sceneId = isset($in['scene_id']) ? (int)$in['scene_id'] : 0;
        if ($sceneId <= 0) return $this->fail('scene_id required', 422);

        $filePath = isset($in['file_path']) ? trim((string)$in['file_path']) : '';
        $fileType = isset($in['file_type']) ? trim((string)$in['file_type']) : '';
        if ($filePath === '') return $this->fail('file_path required', 422);
        if ($fileType === '') return $this->fail('file_type required', 422);

        try {
            $ret = Db::transaction(function () use ($in, $sceneId, $filePath, $fileType) {
                $scene = Db::name('tv_scenes')->where('id', $sceneId)->whereNull('deleted_at')->find();
                if (!$scene) throw new \RuntimeException('scene not found');

                $patientId = (int)$scene['patient_id'];

                $row = [
                    'scene_id'        => $sceneId,
                    'patient_id'      => $patientId,
                    'display_name'    => $in['display_name'] ?? null,
                    'file_path'       => $filePath,
                    'file_type'       => $fileType,
                    'mime'            => $in['mime'] ?? null,
                    'file_size_bytes' => isset($in['file_size_bytes']) ? (int)$in['file_size_bytes'] : null,
                    'file_hash'       => $in['file_hash'] ?? null,
                    'slice_count'     => isset($in['slice_count']) ? (int)$in['slice_count'] : null,
                    'study_uid'       => $in['study_uid'] ?? ($scene['study_uid'] ?? null),
                    'series_uid'      => $in['series_uid'] ?? ($scene['series_uid'] ?? null),
                    'modality'        => $in['modality'] ?? ($scene['modality'] ?? null),
                    'institution'     => $in['institution'] ?? null,
                    'spacing_x_mm'    => $in['spacing_x_mm'] ?? null,
                    'spacing_y_mm'    => $in['spacing_y_mm'] ?? null,
                    'spacing_z_mm'    => $in['spacing_z_mm'] ?? null,
                    'created_at'      => $this->now(),
                    'updated_at'      => $this->now(),
                ];

                $id = Db::name('tv_images')->insertGetId($row);
                $image = Db::name('tv_images')->where('id', $id)->find();
                return ['image' => $image];
            });

            return $this->ok($ret, 'image created');
        } catch (\Throwable $e) {
            return $this->fail('imageCreate failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 获取七牛私有下载 URL（7天有效，剩余<6天才刷新）
     *
     * @param string $key               七牛对象 key
     * @param array  $model             当前模型记录（需包含 private_url / private_expires）
     * @return string
     */
    private function privateQiniuUrl(string $key, array $model = []): string
    {
        $cfg = config('qiniu');

        // 总有效期：7 天
        $ttlTotal   =  7 * 24 * 3600;     // 604800
        // 刷新阈值：6 天
        $ttlRefresh = 1 * 24 * 3600;     // 518400

        $now = time();

        // 如果已有 URL，且有效期还充足，直接复用
        if (!empty($model['private_url']) && !empty($model['private_expires'])) {
            if (($model['private_expires'] - $now) > $ttlRefresh) {
                return $model['private_url'];
            }
        }

        // 否则重新生成
        $auth = new \Qiniu\Auth($cfg['ak'], $cfg['sk']);

        $baseUrl = ltrim($key, '/');
        $expires = $now + $ttlTotal;

        $signedUrl = $auth->privateDownloadUrl($baseUrl, $expires);

        // ⚠️ 这里建议你把新 URL & 过期时间写回数据库
        if (!empty($model['id'])) {
            \think\facade\Db::name('tv_models')
                ->where('id', $model['id'])
                ->update([
                    'private_url'     => $signedUrl,
                    'private_expires' => $expires,
                    'updated_at'      => date('Y-m-d H:i:s'),
                ]);
        }

        return $signedUrl;
    }
    public function sceneDetail(): \think\response\Json
    {
        // 允许 GET
        $sceneId = (int)\think\facade\Request::get('scene_id', 0);
        if ($sceneId <= 0) return $this->fail('scene_id required', 422);

        try {
            $scene = \think\facade\Db::name('tv_scenes')
                ->whereNull('deleted_at')
                ->where('id', $sceneId)
                ->find();

            if (!$scene) return $this->fail('scene not found', 404);

            $models = Db::name('tv_models')
                ->whereNull('deleted_at')
                ->where('scene_id', $sceneId)
                ->order('id', 'asc')
                ->select()
                ->toArray();

            $modelsForView = array_map(function ($m) {
                $key = ltrim(parse_url($m['file_path'], PHP_URL_PATH), '/');
                return [
                    'id'            => (int)$m['id'],
                    'display_name'  => (string)($m['display_name'] ?? ''),
                    'file_path'     => $this->privateQiniuUrl($m['file_path'], $m),
                    'file_type'     => strtolower((string)($m['file_type'] ?? '')),
                    'mime'          => (string)($m['mime'] ?? ''),
                    'file_size'     => isset($m['file_size_bytes']) ? (int)$m['file_size_bytes'] : null,
                    'file_hash'     => (string)($m['file_hash'] ?? ''),
                    'color_hex'     => (string)($m['color_hex'] ?? ''),
                    'material_text' => (string)($m['material_text'] ?? ''),
                    'info_json'     => (string)($m['info_json'] ?? ''),
                ];
            }, $models);

            return $this->ok([
                'scene'  => $scene,
                'models' => $modelsForView,
            ]);
        } catch (\Throwable $e) {
            return $this->fail('sceneDetail failed: ' . $e->getMessage(), 500);
        }
    }
}
