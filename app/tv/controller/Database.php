<?php
declare(strict_types=1);

namespace app\tv;
namespace app\tv\controller;
use think\facade\Db;

/**
 * tv 应用：数据库初始化脚本（ThinkPHP 6.1）
 *
 * 用法示例（在项目根目录执行）：
 *   php think run:init-tv-db
 *
 * 你也可以在任意控制器/命令里调用：
 *   \app\tv\Database::init();
 *   \app\tv\Database::dropAll();
 *   \app\tv\Database::deleteReconstructionBySceneId($sceneId);
 */
class Database
{
    /** @var string 数据库引擎（MySQL/MariaDB） */
    private const ENGINE = 'InnoDB';

    /** @var string 字符集 */
    private const CHARSET = 'utf8mb4';

    /** @var string 排序规则 */
    private const COLLATE = 'utf8mb4_unicode_ci';

    /**
     * 初始化：创建所有表（如果不存在）
     */
    public static function init(): void
    {
        self::createPatientsTable();
        self::createScenesTable();
        self::createModelsTable();
        self::createImagesTable();
    }

    /**
     * 删除所有表（危险操作）
     * 注意：会按外键依赖顺序删除
     */
    public static function dropAll(): void
    {
        // 先关外键检查，避免顺序问题
        self::exec('SET FOREIGN_KEY_CHECKS=0');

        self::exec('DROP TABLE IF EXISTS tv_images');
        self::exec('DROP TABLE IF EXISTS tv_models');
        self::exec('DROP TABLE IF EXISTS tv_scenes');
        self::exec('DROP TABLE IF EXISTS tv_patients');

        self::exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * 删除某个“重建”（按场景 scene_id）
     * - 软删：把 models/images/scenes 的 deleted_at 填上
     * - 如果你更喜欢硬删：把 softDelete=false 传入
     */
    public static function deleteReconstructionBySceneId(int $sceneId, bool $softDelete = true): void
    {
        if ($softDelete) {
            $now = date('Y-m-d H:i:s');
            Db::transaction(function () use ($sceneId, $now) {
                Db::name('tv_models')->where('scene_id', $sceneId)->update([
                    'deleted_at' => $now,
                    'updated_at' => $now,
                ]);
                Db::name('tv_images')->where('scene_id', $sceneId)->update([
                    'deleted_at' => $now,
                    'updated_at' => $now,
                ]);
                Db::name('tv_scenes')->where('id', $sceneId)->update([
                    'deleted_at' => $now,
                    'updated_at' => $now,
                    'status'     => 0, // 0=已删除/失效
                ]);
            });
            return;
        }

        // 硬删：数据库外键已设置 ON DELETE CASCADE，但这里也做双保险
        Db::transaction(function () use ($sceneId) {
            Db::name('tv_models')->where('scene_id', $sceneId)->delete();
            Db::name('tv_images')->where('scene_id', $sceneId)->delete();
            Db::name('tv_scenes')->where('id', $sceneId)->delete();
        });
    }

    // -------------------------
    // 建表
    // -------------------------

    private static function createPatientsTable(): void
    {
        $sql = sprintf(<<<SQL
CREATE TABLE IF NOT EXISTS tv_patients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_uid VARCHAR(64) NOT NULL COMMENT '系统内患者ID（建议：你自己的患者id）',
  name VARCHAR(64) NULL,
  sex VARCHAR(16) NULL COMMENT 'M/F/Unknown',
  birth_date DATE NULL,
  id_number VARCHAR(64) NULL COMMENT '可选：身份证/病案号（建议脱敏后存）',
  phone VARCHAR(32) NULL,
  notes VARCHAR(255) NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uk_patient_uid (patient_uid),
  KEY idx_name (name),
  KEY idx_deleted_at (deleted_at)
) ENGINE=%s DEFAULT CHARSET=%s COLLATE=%s COMMENT='患者表';
SQL, self::ENGINE, self::CHARSET, self::COLLATE);

        self::exec($sql);
    }

    private static function createScenesTable(): void
    {
        $sql = sprintf(<<<SQL
CREATE TABLE IF NOT EXISTS tv_scenes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id BIGINT UNSIGNED NOT NULL COMMENT '关联患者',
  scene_uid VARCHAR(64) NOT NULL COMMENT '场景ID（可用UUID/雪花）',

  title VARCHAR(128) NOT NULL COMMENT '场景名称（例：C5椎弓根螺钉规划）',
  imaging_number VARCHAR(64) NULL COMMENT '影像号/检查号（你说的影像号）',
  imaging_date DATE NULL COMMENT '影像日期',
  recon_date DATE NULL COMMENT '三维重建日期',

  -- 建议补充的DICOM定位字段（可空）
  accession_number VARCHAR(64) NULL,
  study_uid VARCHAR(128) NULL,
  series_uid VARCHAR(128) NULL,
  modality VARCHAR(16) NULL COMMENT 'CT/MR等',

  status TINYINT NOT NULL DEFAULT 1 COMMENT '1=有效 0=删除/失效',
  tags VARCHAR(255) NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uk_scene_uid (scene_uid),
  KEY idx_patient_id (patient_id),
  KEY idx_imaging_date (imaging_date),
  KEY idx_recon_date (recon_date),
  KEY idx_deleted_at (deleted_at),

  CONSTRAINT fk_scene_patient
    FOREIGN KEY (patient_id) REFERENCES tv_patients(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=%s DEFAULT CHARSET=%s COLLATE=%s COMMENT='场景表（患者的一次检查/一次重建/一次规划）';
SQL, self::ENGINE, self::CHARSET, self::COLLATE);

        self::exec($sql);
    }

    private static function createModelsTable(): void
    {
        $sql = sprintf(<<<SQL
CREATE TABLE IF NOT EXISTS tv_models (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scene_id BIGINT UNSIGNED NOT NULL COMMENT '关联场景',

  display_name VARCHAR(128) NOT NULL COMMENT '显示名',
  file_path VARCHAR(512) NOT NULL COMMENT '文件地址（本地/对象存储URL）',
  file_type VARCHAR(32) NOT NULL COMMENT 'stl/obj/glb/ply等',
  mime VARCHAR(128) NULL,
  file_size_bytes BIGINT UNSIGNED NULL,
  file_hash VARCHAR(64) NULL COMMENT '可选：用于去重（sha256/md5）',

  -- 颜色/材料：你说材料优先，那么两者都留，前端决定展示逻辑
  color_hex VARCHAR(16) NULL COMMENT '#RRGGBB 或 rgba(...)',
  material_text VARCHAR(64) NULL COMMENT '材料名称（PLA白/TPU等）',

  -- 详细信息：后续体积/表面积/测量结果都可以放这
  info_json LONGTEXT NULL COMMENT '可存：volume_mm3/surface_mm2/bbox/measurements等',

  -- 可选：分组/用途
  category VARCHAR(64) NULL COMMENT '如：bone/organ/vessel/implant',
  sort_order INT NOT NULL DEFAULT 0,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,

  PRIMARY KEY (id),
  KEY idx_scene_id (scene_id),
  KEY idx_file_type (file_type),
  KEY idx_category (category),
  KEY idx_deleted_at (deleted_at),

  CONSTRAINT fk_model_scene
    FOREIGN KEY (scene_id) REFERENCES tv_scenes(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=%s DEFAULT CHARSET=%s COLLATE=%s COMMENT='三维模型表';
SQL, self::ENGINE, self::CHARSET, self::COLLATE);

        self::exec($sql);
    }

    private static function createImagesTable(): void
    {
        $sql = sprintf(<<<SQL
CREATE TABLE IF NOT EXISTS tv_images (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scene_id BIGINT UNSIGNED NOT NULL COMMENT '关联场景',
  patient_id BIGINT UNSIGNED NOT NULL COMMENT '冗余：便于直接按患者查影像',

  display_name VARCHAR(128) NULL COMMENT '显示名（如：CT原始DICOM）',
  file_path VARCHAR(512) NOT NULL COMMENT '原始影像地址（目录/zip/对象存储URL）',
  file_type VARCHAR(32) NOT NULL COMMENT 'dicom/zip/nifti等',
  mime VARCHAR(128) NULL,
  file_size_bytes BIGINT UNSIGNED NULL,
  file_hash VARCHAR(64) NULL COMMENT '去重',

  -- 影像基本信息（你说的 DICOM 基本信息）
  slice_count INT UNSIGNED NULL COMMENT '层数/切片数（SOP数量）',
  study_uid VARCHAR(128) NULL,
  series_uid VARCHAR(128) NULL,
  modality VARCHAR(16) NULL,
  institution VARCHAR(128) NULL,

  -- 可选：像素间距/层厚，便于后续测量/显示
  spacing_x_mm DECIMAL(10,4) NULL,
  spacing_y_mm DECIMAL(10,4) NULL,
  spacing_z_mm DECIMAL(10,4) NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,

  PRIMARY KEY (id),
  KEY idx_scene_id (scene_id),
  KEY idx_patient_id (patient_id),
  KEY idx_file_type (file_type),
  KEY idx_study_uid (study_uid),
  KEY idx_series_uid (series_uid),
  KEY idx_deleted_at (deleted_at),

  CONSTRAINT fk_image_scene
    FOREIGN KEY (scene_id) REFERENCES tv_scenes(id)
    ON DELETE CASCADE ON UPDATE CASCADE,

  CONSTRAINT fk_image_patient
    FOREIGN KEY (patient_id) REFERENCES tv_patients(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=%s DEFAULT CHARSET=%s COLLATE=%s COMMENT='影像表（原始影像/检查数据）';
SQL, self::ENGINE, self::CHARSET, self::COLLATE);

        self::exec($sql);
    }

    // -------------------------
    // 工具方法
    // -------------------------

    private static function exec(string $sql): void
    {
        Db::execute($sql);
    }
}
