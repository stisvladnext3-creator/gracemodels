"""Build a directly uploadable PHP hosting ZIP; never include runtime secrets."""
from pathlib import Path
import argparse,json,zipfile

parser=argparse.ArgumentParser();parser.add_argument('--output',default='grace-models-php.zip');args=parser.parse_args()
root=Path(__file__).resolve().parents[1];target=Path(args.output)
if not target.is_absolute():target=root/target
files={}
for name in ['index.php','install.php','.htaccess','.user.ini','INSTALL_PHP.md','UPDATE_PHP_V2_1.md','UPDATE_PHP_V2_2.md','UPDATE_PHP_V2_3.md','UPDATE_PHP_V2_4.md','UPDATE_PHP_V2_4_1.md','UPDATE_PHP_V2_5.md','UPDATE_PHP_V2_5_1.md','UPDATE_PHP_V2_5_2.md','SITE_EDITOR.md','MAIL_SETUP.md','LEGAL_DOCUMENTS.md','nginx.example.conf']:
    files[name]=root/name
for file in sorted((root/'app').rglob('*')):
    if file.is_file():files[file.relative_to(root).as_posix()]=file
for file in sorted((root/'tools').glob('*.php')):files[file.relative_to(root).as_posix()]=file
for file in sorted((root/'public').rglob('*')):
    if not file.is_file():continue
    relative=file.relative_to(root/'public').as_posix()
    # Actual static files at the document root also work behind shared-hosting
    # Nginx static caches; HTML templates remain behind the PHP controller.
    destination='public/'+relative if file.suffix=='.html' else relative
    files[destination]=file
metadata={'product':'Grace Models','version':json.loads((root/'package.json').read_text())['version'],'runtime':'PHP 8.1+','databases':['MySQL/MariaDB','SQLite'],'node_required':False,'composer_required':False,'cloudflare_required':False}
target.parent.mkdir(parents=True,exist_ok=True)
with zipfile.ZipFile(target,'w',zipfile.ZIP_DEFLATED,compresslevel=6) as archive:
    for name,file in sorted(files.items()):archive.write(file,name)
    archive.writestr('START_HERE_PHP.md',(root/'INSTALL_PHP.md').read_bytes())
    archive.writestr('BUILD_INFO.json',json.dumps(metadata,ensure_ascii=False,indent=2)+'\n')
with zipfile.ZipFile(target) as archive:
    assert archive.testzip() is None
    names=archive.namelist()
    assert 'index.php' in names and '.htaccess' in names and 'app/api.php' in names and 'images/woman-02.jpg' in names
    assert not any(name in ['config.php','install-key.php'] or name.endswith(('.sqlite','.sqlite-wal','.sqlite-shm')) for name in names)
    assert not any(name.startswith(('worker/','node_modules/','dist/','.git/','.openai/')) for name in names)
print(json.dumps({'archive':str(target),'bytes':target.stat().st_size,'files':len(files)+2,'integrity_verified':True}))
