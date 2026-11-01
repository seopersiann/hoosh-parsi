import os
import sys
import unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parent.parent / 'scripts'))
from build_hosting_release import public_file
from deploy_ftps import run, settings

ENV = {'DEPLOY_HOST':'server26e.irwebspace.com','DEPLOY_PORT':'21','DEPLOY_USER':'git@hoosh-parsi.ir','DEPLOY_PASSWORD':'test-only-not-a-real-password','DEPLOY_PATH':'./public_html/'}

class ReadOnlyFTP:
    def __init__(self, **kw): self.operations=[]
    def connect(self,*a): self.operations.append('connect')
    def login(self,*a): self.operations.append('login')
    def prot_p(self): self.operations.append('encrypted-data')
    def set_pasv(self,*a): pass
    def cwd(self,*a): self.operations.append('cwd')
    def pwd(self): return '/public_html'
    def nlst(self): self.operations.append('list'); return ['index.html']
    def close(self): self.operations.append('close')

class HostingChecks(unittest.TestCase):
    def test_public_export_excludes_credentials_and_development_files(self):
        for name in ['.git/config','.github/workflows/deploy-hosting.yml','.env','scripts/deploy_ftps.py','deliverables/private.html','CNAME','preview.png','README.md','site.zip']:
            self.assertFalse(public_file(name),name)
        for name in ['index.html','google2bf9783b40c309ba.html','about-us/index.html','styles.css','script.js','sitemap_index.xml','robots.txt','assets/fonts/OFL.txt','assets/web-design-shiraz.webp']:
            self.assertTrue(public_file(name),name)
    def test_check_mode_never_calls_write_operations(self):
        ftp=ReadOnlyFTP()
        with patch.dict(os.environ,ENV):run('check',lambda **kw:ftp)
        self.assertIn('encrypted-data',ftp.operations)
        self.assertIn('list',ftp.operations)
        self.assertEqual(ftp.operations[-1],'close')
    def test_unrelated_destination_is_rejected(self):
        for value in ['../public_html/','/','./','/home/other/public_html/']:
            with patch.dict(os.environ,{**ENV,'DEPLOY_PATH':value}):
                with self.assertRaises(ValueError):settings()

if __name__=='__main__':unittest.main()
