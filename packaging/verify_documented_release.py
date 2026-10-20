"""Check README assets and exercise fresh installation setup without starting a service."""
import pathlib,re,subprocess,sys,tarfile,tempfile,zipfile,json
root=pathlib.Path(__file__).resolve().parents[1]
readme=(root/'README.md').read_text(encoding='utf-8')
assets=re.findall(r'!\[[^]]*\]\(([^)]+)\)',readme)
version=json.loads((root/'plugin/port-opener/plugin.json').read_text())['version']
assert len(assets)>=2
assert all((root/path).is_file() for path in assets)
with zipfile.ZipFile(root/f'dist/port-opener-{version}.zip') as archive:
    names=archive.namelist()
    assert all('port-opener/'+path in names for path in assets)
    assert 'meta' not in json.loads(archive.read('port-opener/plugin.json'))
    assert archive.read('port-opener/LICENSE') == (root/'LICENSE').read_bytes()
    assert not any(path.endswith(('token.env','panel-settings.env','state.json')) for path in names)
with tempfile.TemporaryDirectory(prefix='portopener-doc-check-') as temp:
    destination=pathlib.Path(temp)
    with tarfile.open(root/f'dist/port-opener-service-{version}.tar.gz') as archive:
        archive.extractall(destination,filter='data')
    source=destination/'port-opener-service'
    assert all((source/path).is_file() for path in assets)
    assert (source/'outbound.py').is_file()
    assert (source/'compose.outbound.yml').is_file()
    command=[sys.executable,'configure_new_install.py','--node-id','1','--host-ip','192.168.178.50',
        '--router-url','http://192.168.178.1:49000/igddesc.xml']
    subprocess.run(command,cwd=source,check=True,capture_output=True)
    config=json.loads((source/'service.json').read_text())
    assert config['dry_run'] is True and config['bind']=='127.0.0.1'
    assert subprocess.run(command,cwd=source,capture_output=True).returncode!=0
    subprocess.run(['docker','compose','config','--quiet'],cwd=source,check=True,capture_output=True)
    subprocess.run(['docker','compose','-f','compose.outbound.yml','config','--quiet'],cwd=source,check=True,capture_output=True)
print('README assets, packaged files, fresh configuration, overwrite refusal and Compose syntax verified; no service started.')
