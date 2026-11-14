"""Build the VirtueMart payment plugin zip: copies the ArionPay PHP SDK into arionpay/lib, then zips it.
   python3 build.py  ->  dist/plg_vmpayment_arionpay-1.0.0.zip  (Joomla: System > Install > Extensions > Upload)"""
import os, shutil, zipfile, pathlib
HERE = pathlib.Path(__file__).resolve().parent
SDK = HERE.parent / "arionpay-php"
PLUGIN = HERE / "arionpay"
lib = PLUGIN / "lib" / "arionpay-php"
if lib.exists():
    shutil.rmtree(lib)
(lib / "src").mkdir(parents=True)
for f in ("autoload.php", "LICENSE"):
    shutil.copy(SDK / f, lib / f)
for f in sorted((SDK / "src").glob("*.php")):
    shutil.copy(f, lib / "src" / f.name)
guard = "<!DOCTYPE html><title></title>\n"
for d in (PLUGIN / "lib", lib, lib / "src"):
    (d / "index.html").write_text(guard)
(HERE / "dist").mkdir(exist_ok=True)
out = HERE / "dist" / "plg_vmpayment_arionpay-1.0.0.zip"
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(PLUGIN):
        dirs.sort()
        for name in sorted(files):
            p = pathlib.Path(root) / name
            z.write(p, str(p.relative_to(PLUGIN)))
print(out, os.path.getsize(out), "bytes")
