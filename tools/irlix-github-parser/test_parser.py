import base64
import csv
import io
import json
import sqlite3
import tempfile
import unittest
import zipfile
from pathlib import Path

import parser as m


class FakeGitHub:
    token = None
    def __init__(self):
        self.requests = []
        self.files = {
            'buyer/new': 'На проекте СберТех 2026 работали аутстафф специалисты QA Python. Пишите jobs@example.ru +7 (912) 345-67-89.',
            'vendor/new': 'Компания RedLab предоставляет IT-аутстафф и разрабатывает React продукты.',
            'bad/unreadable': None,
        }
    def search(self, kind, query, page):
        self.requests.append((kind,query,page))
        return {'total_count':4, 'items': [
            {'full_name':'OLD/repo'},
            {'full_name':'buyer/new'},
            {'full_name':'vendor/new'},
            {'full_name':'bad/unreadable'},
        ] if page==1 else []}
    def file(self, repo, path=None):
        if repo=='bad/unreadable':return None
        v=self.files.get(repo)
        if v is None: raise m.ApiError(404,'not found')
        return {'content':v,'path':'README.md','url':f'https://github.com/{repo}/blob/main/README.md','sha':'a'*40}


class ParserTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory()
        self.folder=Path(self.temp.name)
        self.con=m.connect(self.folder/'parser.sqlite')
        state={'unique_repositories_from_workbook':['OLD/repo'],
               'status':{'currently_saved_last_pass':394,'additional_repos_since_65_reported':329}}
        self.arc=self.folder/'archive.zip'
        with zipfile.ZipFile(self.arc,'w') as z:
            z.writestr('github_intelligence_state_394_2026-10-26.json',json.dumps(state))
        self.assertEqual(m.init_from_archive(self.con,self.arc),(1,394))

    def tearDown(self):
        self.con.close()
        self.temp.cleanup()

    def test_bootstrap_is_idempotent_protective(self):
        with self.assertRaises(RuntimeError):m.init_from_archive(self.con,self.arc)
        self.assertEqual(m.stats(self.con)['next_pass_number'],395)
        self.assertEqual(m.stats(self.con)['unique_repos_total'],1)

    def test_resume_mid_page_and_grade_not_A_or_B(self):
        fake=FakeGitHub()
        n,_=m.scan(self.con,fake,max_repos=1,mode='repo',max_api_pages=3)
        self.assertEqual(n,1)
        self.assertEqual(m.stats(self.con)['next_pass_number'],396)
        job=self.con.execute('select * from jobs where status="pending" order by id limit 1').fetchone()
        self.assertEqual(job['offset'],2)
        # Restart process: the same query page must not produce the same pass twice.
        n2,_=m.scan(self.con,fake,max_repos=1,mode='repo',max_api_pages=3)
        self.assertEqual(n2,1)
        repo_count=self.con.execute("select count(*) from repos where status='checked'").fetchone()[0]
        self.assertEqual(repo_count,2)
        self.assertEqual(m.stats(self.con)['next_pass_number'],397)
        self.assertEqual(m.stats(self.con)['target_50000_completed'],331)
        self.assertEqual(self.con.execute("select status from repos where repo='OLD/repo'").fetchone()[0],'legacy')
        self.assertNotIn('bad/unreadable',[r[0] for r in self.con.execute("select repo from repos where status='checked'")])

    def test_unreadable_not_counted(self):
        fake=FakeGitHub()
        fake.files={'bad/unreadable':None}
        n,_=m.scan(self.con,fake,max_repos=1,mode='repo',max_api_pages=2)
        self.assertEqual(n,0)
        self.assertEqual(m.stats(self.con)['next_pass_number'],395)
        self.assertGreaterEqual(m.stats(self.con)['unreadable_skipped_repos'],1)

    def test_redaction_and_no_raw_storage(self):
        text='СберТех: аутстафф QA. Contact test@company.ru phone +7 912 345 67 89 and @operatorname'
        a=m.analyze('buyer/new','README.md',text)
        self.assertEqual(a['class'],'C')
        self.assertTrue(a['needs_review'])
        self.assertNotIn('test@company.ru',a['summary'])
        self.assertNotIn('912 345',a['summary'])
        self.assertNotIn('@operatorname',a['summary'])
        num=m.register_pass(self.con,'buyer/new',{'path':'README.md','url':'https://github.com/buyer/new/blob/main/README.md','sha':'1'},a)
        self.assertEqual(num,395)
        self.assertIsNone(m.register_pass(self.con,'BUYER/NEW',{'path':'README.md','url':'https://github.com/buyer/new/blob/main/README.md','sha':'1'},a))
        out=self.folder/'reports'
        files=m.export(self.con,out)
        self.assertEqual(len(files),4)
        self.assertNotIn('test@company.ru',(out/'checked_repos.csv').read_text('utf-8-sig'))
        with (out/'sales_review_queue.csv').open(encoding='utf-8-sig',newline='') as f:
            self.assertEqual(len(list(csv.DictReader(f))),1)

    def test_partition_date_for_large_search(self):
        with self.con:
            self.con.execute('insert into jobs(kind,query) values (?,?)',('repositories','"outstaff" in:readme created:2026-01-01..2026-12-31'))
        job=self.con.execute('select * from jobs').fetchone()
        self.assertTrue(m.split_job_if_capped(self.con,job,1200))
        self.assertEqual(self.con.execute("select count(*) from jobs where status='pending'").fetchone()[0],2)
        self.assertEqual(self.con.execute("select status from jobs where id=?",(job['id'],)).fetchone()[0],'split')

    def test_rate_limited_preserves_cursor(self):
        class Limit(FakeGitHub):
            def file(self,repo,path=None):
                raise m.RateLimited('test rate limit')
        fake=Limit()
        with self.assertRaises(m.RateLimited):m.scan(self.con,fake,max_repos=1,mode='repo')
        self.assertEqual(m.stats(self.con)['next_pass_number'],395)
        self.assertEqual(self.con.execute('select offset from jobs where status="pending" order by id limit 1').fetchone()[0],1) # legacy skip, next item retained

    def test_only_file_read_counts(self):
        a=m.analyze('misc/job','README.md','Java Python банк обычная штатная вакансия в 2026 году')
        self.assertNotIn(a['class'], ('A','B'))
        self.assertEqual(a['needs_review'],0)


if __name__=='__main__':unittest.main()
