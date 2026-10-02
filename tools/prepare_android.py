#!/usr/bin/env python3
"""Prepare an isolated Android WebView project from a URL or HTML/ZIP upload."""

from __future__ import annotations

import json
import os
import shutil
import sys
import urllib.error
import urllib.request
import zipfile
from pathlib import Path, PurePosixPath
from xml.sax.saxutils import escape

from PIL import Image, ImageDraw, ImageOps

ROOT = Path(__file__).resolve().parents[1]
TEMPLATE = ROOT / "android" / "template"
OUTPUT = ROOT / ".apk-builder" / "android"
SITE = OUTPUT / "app" / "src" / "main" / "assets" / "site"
ASSETS = OUTPUT / "app" / "src" / "main" / "assets"
MAX_DOWNLOAD = 8 * 1024 * 1024
MAX_UNZIPPED = 100 * 1024 * 1024
MAX_ZIP_FILES = 5000


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise ValueError(f"Required build input is missing: {name}")
    return value


def download(url: str, destination: Path, max_bytes: int = MAX_DOWNLOAD) -> None:
    if not url.startswith("https://"):
        raise ValueError("Build source links must use HTTPS.")
    request = urllib.request.Request(url, headers={"User-Agent": "MrAiPrime-APK-Builder/1.0"})
    try:
        with urllib.request.urlopen(request, timeout=45) as response, destination.open("wb") as output:
            length = response.headers.get("Content-Length")
            if length and int(length) > max_bytes:
                raise ValueError("Uploaded build file exceeds the download limit.")
            total = 0
            while True:
                chunk = response.read(1024 * 1024)
                if not chunk:
                    break
                total += len(chunk)
                if total > max_bytes:
                    raise ValueError("Uploaded build file exceeds the download limit.")
                output.write(chunk)
    except (urllib.error.URLError, TimeoutError) as error:
        raise ValueError(f"Could not download the uploaded build source: {error}") from error


def safe_extract(zip_path: Path, destination: Path) -> None:
    destination.mkdir(parents=True, exist_ok=True)
    total_size = 0
    with zipfile.ZipFile(zip_path) as archive:
        entries = archive.infolist()
        if len(entries) > MAX_ZIP_FILES:
            raise ValueError("ZIP contains too many files (maximum 5,000).")
        for entry in entries:
            raw_name = entry.filename.replace("\\", "/")
            relative = PurePosixPath(raw_name)
            mode = entry.external_attr >> 16
            if relative.is_absolute() or any(part in ("", ".", "..") for part in relative.parts):
                raise ValueError("ZIP contains an unsafe file path.")
            if (mode & 0o170000) == 0o120000:
                raise ValueError("ZIP symbolic links are not supported.")
            total_size += entry.file_size
            if total_size > MAX_UNZIPPED:
                raise ValueError("ZIP expands beyond the 100 MB safety limit.")
            target = destination.joinpath(*relative.parts)
            if entry.is_dir():
                target.mkdir(parents=True, exist_ok=True)
                continue
            target.parent.mkdir(parents=True, exist_ok=True)
            with archive.open(entry) as source, target.open("wb") as output:
                shutil.copyfileobj(source, output, length=1024 * 1024)


def locate_site(root: Path) -> Path:
    indexes = [path for path in root.rglob("*") if path.is_file() and path.name.lower() == "index.html"]
    if not indexes:
        indexes = [path for path in root.rglob("*") if path.is_file() and path.name.lower() == "index.htm"]
    if not indexes:
        raise ValueError("HTML/ZIP source needs an index.html or index.htm file.")
    indexes.sort(key=lambda path: (len(path.relative_to(root).parts), str(path).lower()))
    return indexes[0].parent


def copy_site(source: Path, mode: str, work: Path) -> None:
    if mode == "url":
        return
    SITE.mkdir(parents=True, exist_ok=True)
    if not zipfile.is_zipfile(source):
        if source.stat().st_size > MAX_DOWNLOAD:
            raise ValueError("HTML file exceeds the upload limit.")
        shutil.copy2(source, SITE / "index.html")
        return

    extracted = work / "extracted"
    safe_extract(source, extracted)
    site_root = locate_site(extracted)
    selected_index = site_root / next(
        item.name
        for item in site_root.iterdir()
        if item.is_file() and item.name.lower() in ("index.html", "index.htm")
    )
    for item in site_root.iterdir():
        target_name = "index.html" if item == selected_index else item.name
        target = SITE / target_name
        if item.is_dir():
            shutil.copytree(item, target, dirs_exist_ok=True)
        else:
            shutil.copy2(item, target)


def create_default_icon() -> Image.Image:
    image = Image.new("RGBA", (512, 512), (5, 150, 105, 255))
    draw = ImageDraw.Draw(image)
    draw.rounded_rectangle((160, 70, 352, 442), radius=34, outline="white", width=24)
    draw.rounded_rectangle((184, 116, 328, 374), radius=8, fill=(255, 255, 255, 255))
    draw.ellipse((241, 394, 271, 424), fill="white")
    return image


def write_icons(icon_url: str, work: Path) -> None:
    icon = create_default_icon()
    if icon_url:
        source = work / "uploaded-icon"
        download(icon_url, source, 1 * 1024 * 1024)
        try:
            with Image.open(source) as opened:
                icon = opened.convert("RGBA")
                icon.load()
        except Exception as error:
            raise ValueError("Uploaded app icon must be a valid PNG or JPEG image.") from error

    mipmap = OUTPUT / "app" / "src" / "main" / "res"
    for density, size in (("mdpi", 48), ("hdpi", 72), ("xhdpi", 96), ("xxhdpi", 144), ("xxxhdpi", 192)):
        folder = mipmap / f"mipmap-{density}"
        folder.mkdir(parents=True, exist_ok=True)
        canvas = Image.new("RGBA", (size, size), (0, 0, 0, 0))
        fitted = ImageOps.contain(icon, (size, size), method=Image.Resampling.LANCZOS)
        canvas.alpha_composite(fitted, ((size - fitted.width) // 2, (size - fitted.height) // 2))
        canvas.save(folder / "ic_launcher.png", format="PNG", optimize=True)


def main() -> None:
    job_id = required("BUILD_JOB_ID")
    mode = required("BUILD_MODE")
    app_name = required("BUILD_APP_NAME")
    package_name = required("BUILD_PACKAGE_NAME")
    orientation = required("BUILD_ORIENTATION")
    refresh = os.environ.get("BUILD_REFRESH", "false").lower() == "true"
    source_url = required("BUILD_SOURCE_URL")
    icon_url = os.environ.get("BUILD_ICON_URL", "").strip()
    if mode not in ("url", "file"):
        raise ValueError("Build mode must be url or file.")
    if orientation not in ("portrait", "landscape", "unspecified"):
        raise ValueError("Unsupported screen orientation.")
    if not job_id.isalnum():
        raise ValueError("Build request ID is invalid.")

    if OUTPUT.exists():
        shutil.rmtree(OUTPUT)
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    shutil.copytree(TEMPLATE, OUTPUT)
    (ASSETS / "site").mkdir(parents=True, exist_ok=True)
    work = OUTPUT.parent / "input"
    if work.exists():
        shutil.rmtree(work)
    work.mkdir(parents=True)

    if mode == "file":
        source_path = work / "source-upload"
        download(source_url, source_path)
        copy_site(source_path, mode, work)
    elif not source_url.startswith(("https://", "http://")):
        raise ValueError("Website URL must begin with HTTP or HTTPS.")

    write_icons(icon_url, work)
    config = {
        "mode": mode,
        "target": source_url if mode == "url" else "",
        "refresh": refresh,
    }
    (ASSETS / "app-config.json").write_text(json.dumps(config, ensure_ascii=False), encoding="utf-8")
    safe_name = escape(app_name)
    (OUTPUT / "app" / "src" / "main" / "res" / "values" / "strings.xml").write_text(
        f'<?xml version="1.0" encoding="utf-8"?>\n<resources>\n    <string name="app_name">{safe_name}</string>\n</resources>\n',
        encoding="utf-8",
    )
    shutil.rmtree(work, ignore_errors=True)
    print(f"Prepared {mode} app source for {package_name} ({job_id}).")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print(f"ERROR: {error}", file=sys.stderr)
        sys.exit(1)
