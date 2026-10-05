from pathlib import Path
import zipfile

root = Path(__file__).resolve().parents[1]
output = root / 'dist' / 'Mautic-Multi-Mail-v0.1.0.zip'
output.parent.mkdir(exist_ok=True)
vendor = root / 'build' / 'dependencies' / 'vendor'
if not (vendor / 'autoload.php').is_file():
    raise SystemExit('Run composer install --working-dir=build/dependencies first.')
with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
    for path in root.rglob('*'):
        if not path.is_file():
            continue
        relative = path.relative_to(root)
        if relative.parts[0] in ('.git', 'dist', 'build') or relative.name == '.gitignore':
            continue
        archive.write(path, 'MauticMultiMailBundle/' + str(relative))
    for path in vendor.rglob('*'):
        if path.is_file():
            archive.write(path, 'MauticMultiMailBundle/vendor/' + str(path.relative_to(vendor)))
print(output)
