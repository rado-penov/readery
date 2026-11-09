"""Build index.html by inlining src/core.js into src/page.html.

Usage (from the orderconverter folder):  python tools/build.py
"""
from pathlib import Path

root = Path(__file__).resolve().parent.parent
page = (root / "src" / "page.html").read_text(encoding="utf-8")
core = (root / "src" / "core.js").read_text(encoding="utf-8")
if "/*__CORE__*/" not in page:
    raise SystemExit("Marker /*__CORE__*/ not found in src/page.html")
(root / "index.html").write_text(page.replace("/*__CORE__*/", core), encoding="utf-8")
print("Built", root / "index.html")
