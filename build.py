"""Builds dist/cta-tracking-<version>.zip for upload: python build.py

Not Compress-Archive: Windows PowerShell 5.1 writes paths with backslashes, which a Linux host unpacks
as file names, and WordPress then cannot find the plugin file.
"""
import pathlib, re, zipfile

root = pathlib.Path(__file__).parent
version = re.search(r"Version:\s*(\S+)", (root / "cta-tracking.php").read_text(encoding="utf-8")).group(1)
out = root / "dist" / f"cta-tracking-{version}.zip"
out.parent.mkdir(exist_ok=True)
files = ["cta-tracking.php", "uninstall.php", "README.md", "LICENSE"] + [
    p.relative_to(root).as_posix() for d in ("includes", "assets") for p in sorted((root / d).rglob("*")) if p.is_file()
]
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for f in files:
        z.write(root / f, "cta-tracking/" + f)  # forward slashes, one top folder
print(out)
for i in zipfile.ZipFile(out).infolist():
    assert "\\" not in i.orig_filename and i.orig_filename.startswith("cta-tracking/"), i.orig_filename
print(len(files), "files, paths ok")
