import os
import argparse
import subprocess
import hashlib
import json
import time
from pathlib import Path

import requests
from tqdm import tqdm
from qiniu import Auth, put_file

# =========================
# 固定配置（你只需要改一次）
# =========================

API_BASE = "https://j.ipxyy.com/tv/api"

# draco_encoder.exe（同目录）
DRACO_ENCODER = Path(__file__).resolve().parent / "draco_encoder"
DRACO_COMPRESSION_LEVEL = "10"

# =========================
# 读取 ThinkPHP INI 风格 .env（根目录）
# =========================

import configparser

def load_env_ini(env_path: Path) -> dict:
    cfg = configparser.ConfigParser()
    cfg.read(env_path, encoding="utf-8")
    return {
        "QINIU_AK": cfg.get("QINIU", "AK", fallback=""),
        "QINIU_SK": cfg.get("QINIU", "SK", fallback=""),
        "QINIU_BUCKET": cfg.get("QINIU", "BUCKET", fallback=""),
        "QINIU_DOMAIN": cfg.get("QINIU", "DOMAIN", fallback=""),
    }

ENV_PATH = Path(__file__).resolve().parent.parent / ".env"
env = load_env_ini(ENV_PATH)

QINIU_AK = env["QINIU_AK"]
QINIU_SK = env["QINIU_SK"]
QINIU_BUCKET = env["QINIU_BUCKET"]
QINIU_DOMAIN = env["QINIU_DOMAIN"]

TV_API_TOKEN = os.getenv("TV_API_TOKEN", "")

if not QINIU_AK or not QINIU_SK:
    raise RuntimeError("未在 .env 的 [QINIU] 段中读取到 AK/SK")


# =========================
# 工具函数
# =========================

def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def run_draco_encoder(stl_path: Path, out_path: Path):
    exe = str(DRACO_ENCODER)
    if DRACO_ENCODER.suffix.lower() != ".exe" and os.name == "nt":
        # 你如果是 Windows，建议把 draco_encoder.exe 放同目录
        pass
    cmd = [
        exe,
        "-i", str(stl_path),
        "-o", str(out_path),
        "-cl", DRACO_COMPRESSION_LEVEL,
    ]
    subprocess.check_call(cmd)


def upload_to_qiniu(local_path: Path, remote_key: str) -> str:
    q = Auth(QINIU_AK, QINIU_SK)
    token = q.upload_token(QINIU_BUCKET, remote_key)
    ret, info = put_file(token, remote_key, str(local_path))
    if info.status_code != 200:
        raise RuntimeError(f"Qiniu upload failed: {info}")
    return f"{QINIU_DOMAIN}/{remote_key}"


def api_post(endpoint: str, payload: dict) -> dict:
    headers = {"Content-Type": "application/json"}
    if TV_API_TOKEN:
        headers["X-TV-TOKEN"] = TV_API_TOKEN

    r = requests.post(
        f"{API_BASE}/{endpoint}",
        headers=headers,
        data=json.dumps(payload, ensure_ascii=False),
        timeout=30,
    )
    r.raise_for_status()
    return r.json()


def now_ts_ms() -> int:
    # 毫秒级时间戳
    return int(time.time() * 1000)


def create_scene(patient_id: int, scene_name: str) -> int:
    """
    调用后端创建场景，返回 scene_id
    依赖接口：POST /tv/api/sceneCreate
    """
    payload = {
        "patient_id": patient_id,
        "title": scene_name,
        # 你需要的话也可以加 imaging_number / imaging_date / recon_date 等
    }
    resp = api_post("sceneCreate", payload)
    if resp.get("code") != 0:
        raise RuntimeError(f"sceneCreate 失败: {resp}")
    scene = (resp.get("data") or {}).get("scene") or {}
    scene_id = int(scene.get("id") or 0)
    if scene_id <= 0:
        raise RuntimeError(f"sceneCreate 返回的 scene_id 无效: {resp}")
    return scene_id


# =========================
# 主逻辑
# =========================

def main():
    parser = argparse.ArgumentParser(description="STL → Draco → 七牛云 → TV 数据库（自动建场景）")
    parser.add_argument("--dir", required=True, help="包含 STL 文件的目录（绝对或相对路径）")
    parser.add_argument("--patient-id-number", required=True, help="患者 id_number（用于七牛路径）")
    parser.add_argument("--patient-id", type=int, required=True, help="患者 patient_id（用于 sceneCreate）")
    parser.add_argument("--scene-name", default="测试场景", help="场景名称（默认：测试场景）")

    args = parser.parse_args()

    src_dir = Path(args.dir).resolve()
    if not src_dir.exists() or not src_dir.is_dir():
        raise RuntimeError(f"目录不存在: {src_dir}")

    patient_id_number = args.patient_id_number
    patient_id = int(args.patient_id)
    scene_name = str(args.scene_name).strip() or "测试场景"

    stl_files = sorted(src_dir.glob("*.stl"))
    if not stl_files:
        print(f"目录中没有 STL 文件: {src_dir}")
        return

    # 0) 自动创建场景（只创建一次）
    scene_id = create_scene(patient_id=patient_id, scene_name=scene_name)

    print(f"STL 目录: {src_dir}")
    print(f"患者 ID Number: {patient_id_number}")
    print(f"患者 Patient ID: {patient_id}")
    print(f"自动创建场景: {scene_name}  -> scene_id={scene_id}")
    print("-" * 60)

    for stl in tqdm(stl_files, desc="Processing STL"):
        drc = stl.with_suffix(".drc")

        # 1) Draco 压缩
        run_draco_encoder(stl, drc)

        # 2) 上传七牛：加时间戳前缀，避免同名冲突
        ts = now_ts_ms()
        remote_name = f"{ts}_{drc.name}"  # 1700000000000_xxx.drc
        remote_key = f"models/{patient_id_number}/{remote_name}"
        url = upload_to_qiniu(drc, remote_key)

        # 3) 写入数据库
        payload = {
            "scene_id": scene_id,
            "display_name": stl.stem,
            "file_path": url,
            "file_type": "draco",
            "mime": "model/draco",
            "file_size_bytes": drc.stat().st_size,
            "file_hash": sha256_file(drc),
            "category": "model",
            "info_json": json.dumps(
                {
                    "source": "stl",
                    "compressed": True,
                    "draco_level": int(DRACO_COMPRESSION_LEVEL),
                    "original_file": stl.name,
                    "qiniu_key": remote_key,
                    "uploaded_at_ms": ts,
                },
                ensure_ascii=False,
            ),
        }

        resp = api_post("modelCreate", payload)
        if resp.get("code") != 0:
            raise RuntimeError(f"数据库写入失败: {resp}")

        print(f"\n[OK] {stl.name} → {url}")

    print("\n✅ 全部 STL 已处理完成")


if __name__ == "__main__":
    main()
