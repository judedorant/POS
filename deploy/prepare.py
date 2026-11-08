#!/usr/bin/env python3
"""Run once as root on the VPS. Never prints credentials."""
import base64, os, re, secrets, subprocess, sys
from pathlib import Path
if os.geteuid() != 0:
    sys.exit('Run as root to set PostgreSQL private-key ownership (UID 999).')
if len(sys.argv) != 3 or not re.fullmatch(r'[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?', sys.argv[1]) or '.' not in sys.argv[1] or not re.fullmatch(r'age1[0-9a-z]{58}', sys.argv[2]):
    sys.exit('Usage: sudo python3 deploy/prepare.py pos.example.com age1PUBLIC_RECIPIENT')
domain,recipient=sys.argv[1:]
root=Path(__file__).resolve().parent
state=root/'state'
if state.exists(): sys.exit('Refusing to overwrite existing deploy/state. Rotate credentials explicitly.')
os.umask(0o077)
state.mkdir(mode=0o700)
certs=state/'certs';certs.mkdir(mode=0o755);certs.chmod(0o755)
private=state/'private';private.mkdir()
app,migrator,admin,backup,reverb,key=[secrets.token_hex(32) for _ in range(6)]
runtime=(root/'runtime.env.example').read_text().replace('pos.example.com',domain)
for a,b in {'REPLACE_WITH_GENERATED_KEY':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(), 'REPLACE_APP_PASSWORD':app,'REPLACE_PUBLIC_RANDOM_KEY':key,'REPLACE_SECRET_RANDOM_KEY':reverb}.items():runtime=runtime.replace(a,b)
(state/'runtime.env').write_text(runtime)
infra=(root/'infrastructure.env.example').read_text().replace('pos.example.com',domain)
for a,b in {'REPLACE_ADMIN_PASSWORD':admin,'REPLACE_APP_PASSWORD':app,'REPLACE_MIGRATOR_PASSWORD':migrator,'REPLACE_BACKUP_PASSWORD':backup,'REPLACE_AGE_PUBLIC_RECIPIENT':recipient}.items():infra=infra.replace(a,b)
(state/'infrastructure.env').write_text(infra)
def run(*args):subprocess.run(args,check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
run('openssl','req','-x509','-newkey','rsa:3072','-nodes','-keyout',str(private/'ca.key'),'-out',str(certs/'ca.crt'),'-days','3650','-subj','/CN=POS Database CA','-addext','basicConstraints=critical,CA:TRUE')
run('openssl','req','-newkey','rsa:3072','-nodes','-keyout',str(certs/'server.key'),'-out',str(private/'server.csr'),'-subj','/CN=db')
(private/'server.ext').write_text('subjectAltName=DNS:db\nextendedKeyUsage=serverAuth\nbasicConstraints=CA:FALSE\n')
run('openssl','x509','-req','-in',str(private/'server.csr'),'-CA',str(certs/'ca.crt'),'-CAkey',str(private/'ca.key'),'-CAcreateserial','-out',str(certs/'server.crt'),'-days','365','-extfile',str(private/'server.ext'))
for name in ['ca.crt','server.crt']:(certs/name).chmod(0o644)
os.chown(certs/'server.key',999,999);(certs/'server.key').chmod(0o400)
backups=state/'backups';backups.mkdir();os.chown(backups,10001,10001);backups.chmod(0o700)
print('Prepared deploy/state. Add Stripe TEST credentials in runtime.env; keep state out of source control. Database certificate expires in 365 days.')
