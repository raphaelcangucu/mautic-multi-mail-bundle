from pathlib import Path
import zipfile
import subprocess
import os

root = Path(__file__).resolve().parents[1]
output = root / 'dist' / 'Mautic-Multi-Mail-v0.3.0.zip'
output.parent.mkdir(exist_ok=True)
vendor = root / 'build' / 'dependencies' / 'vendor'
if not (vendor / 'autoload.php').is_file():
    raise SystemExit('Run composer install --working-dir=build/dependencies first.')
# Keep form/validator test dependencies out of the installable runtime ZIP.
vendor = root / 'build' / 'package-vendor'
env = os.environ.copy()
env['COMPOSER_VENDOR_DIR'] = str(vendor)
subprocess.run(['composer', 'install', '--working-dir=' + str(root / 'build' / 'dependencies'),
                '--no-dev', '--no-scripts', '--no-interaction', '--no-progress', '--prefer-dist'], env=env, check=True)
with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
    if (root / '.git').exists():
        tracked = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root).decode().split('\0')
        source_paths = [root / name for name in tracked if name]
    else:
        # Source archives have no Git index: allow only plugin source folders/files.
        folders = ('Application', 'Assets', 'Config', 'Controller', 'DependencyInjection', 'EventSubscriber', 'Form', 'Mailer', 'Resources', 'Tests', 'Translations')
        source_paths = [path for folder in folders for path in (root / folder).rglob('*')]
        source_paths += [root / name for name in ('MauticMultiMailBundle.php', 'composer.json', 'README.md', 'CONTRIBUTING.md', 'LICENSE.txt')]
    for path in source_paths:
        if not path.is_file():
            continue
        relative = path.relative_to(root)
        if relative.parts[0] in ('.git', 'dist', 'build', 'vendor', '.github') or relative.name in ('.gitignore', '.DS_Store', 'connections.json', 'connections.lock') or relative.name.startswith('.env') or any(part.endswith('-private') for part in relative.parts):
            continue
        archive.write(path, 'MauticMultiMailBundle/' + str(relative))
    for path in vendor.rglob('*'):
        if path.is_file():
            archive.write(path, 'MauticMultiMailBundle/vendor/' + str(path.relative_to(vendor)))
print(output)
