"""Regression tests for public buyer signals and 50k pass-count accuracy."""
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from irlix_intel.analyze import analyze
from irlix_intel.extract import Unit
from irlix_intel.pipeline import Pipeline, read_repos
from irlix_intel.sales import profile_units, score_lead
from irlix_intel.store import Store


class SalesV11(unittest.TestCase):
    def test_ts_outstaff_workplaces_are_separate_and_false_ignored(self):
        text = '''
const RNCB: TExperience = {
 company: {name:'RNCB'}, isOutstaff: false, jobTitle:'Engineer'
};
const Xsolla: TExperience = {
 company: {logo: xx, name:'Xsolla'},
 jobTitle:'Senior Developer',
 isOutstaff: true,
 dateBegin:new Date('2020-02-01'), techStack:[React, TypeScript]
};
const VK: TExperience = {
 company: {name:'VK'}, isOutstaff:true,
 dateBegin:new Date('2020-10-01'),techStack:[React, Redux]
};'''
        x=profile_units(text.encode(),'work-experience.tsx')
        self.assertEqual([i['profile_customer'] for i in x], ['Xsolla','VK'])
        self.assertEqual(len({i['locator'] for i in x}),2)

    def test_html_redlab_citydrive_megamarket_no_crossjoin(self):
        doc='''<div class="timeline__item reveal"><div class="timeline__period">Sep 2024 — Present</div>
        <h3 class="timeline__company">Citydrive</h3>
        <p class="timeline__role">Golang Developer — via RedLab outstaffing</p></div>
        <div class="timeline__item reveal"><div class="timeline__period">Sep 2021 — Aug 2024</div>
        <h3 class="timeline__company">Megamarket (Sber ecosystem)</h3>
        <p class="timeline__role">Golang Developer — via RedLab outstaffing</p></div>'''
        x=profile_units(doc.encode(),'index.html')
        self.assertEqual([(i['profile_customer'],i['profile_supplier']) for i in x],
                         [('Citydrive','RedLab'),('Megamarket (Sber ecosystem)','RedLab')])
        self.assertTrue(all('outstaffing' in i['text'] for i in x))

    def test_pipeline_html_real_relationships_to_sqlite(self):
        raw = b'''<div class="timeline__item reveal"><div class="timeline__period">2024</div>
        <h3 class="timeline__company">Citydrive</h3>
        <p class="timeline__role">Golang Developer via RedLab outstaffing</p></div>
        <div class="timeline__item reveal"><div class="timeline__period">2021</div>
        <h3 class="timeline__company">Megamarket</h3>
        <p class="timeline__role">Golang Developer via RedLab outstaffing</p></div>'''
        with tempfile.TemporaryDirectory() as temp:
            st=Store(Path(temp)/'db.sqlite')
            st.add_repo('source/portfolio')
            fid=st.add_file('source/portfolio','index.html','a'*40,'b'*40,
                           'https://github.com/source/portfolio/blob/'+'b'*40+'/index.html',len(raw))
            file=st.rows('SELECT * FROM files WHERE id=?',(fid,))[0]
            args=SimpleNamespace(max_repos=5,max_files_per_repo=30,max_file_mb=5,refresh_hours=24)
            p=Pipeline(st,None,args,known=set(),excluded=set(),aliases={})
            p.process(file,raw)
            data=[__import__('json').loads(x['data']) for x in st.rows('SELECT data FROM findings')]
            pairs={(d['customers'][0],d['suppliers'][0]) for d in data if d.get('category')=='employee_named_outstaff_pair_claim'}
            self.assertEqual(pairs,{('Citydrive','RedLab'),('Megamarket','RedLab')})
            self.assertEqual(st.progress_stats()['next_pass'],396)
            self.assertEqual(p.stats['new_verified_repos'],1)
            st.close()

    def test_plain_logo_list_not_named_pair(self):
        doc=b'<div class="clients">RedLab Citydrive Megamarket. Outstaffing</div>'
        self.assertEqual(profile_units(doc,'index.html'),[])

    def test_score_independent_and_supplier_only_not_direct_buyer(self):
        x=score_lead(dict(customers=[],suppliers=['RedLab'],grade='C',stacks=['Go'],
                               outstaff=True,geography='RF_cue',years=['2026']))
        self.assertEqual(x['sales_lane'],'supplier_partner_or_context')
        self.assertLessEqual(x['sales_score'],39)
        self.assertFalse(x['demand_current_confirmed'])

    def test_completed_pass_only_after_read_and_durable(self):
        with tempfile.TemporaryDirectory() as temp:
            path=Path(temp)/'test.db'
            st=Store(path)
            self.assertEqual(st.progress_stats()['next_pass'],395)
            st.add_repo('new/one')
            ident=st.add_file('new/one','test.md','f'*40,'c'*40,'https://github.com/new/one',18)
            self.assertIsNone(st.verified('new/one',ident))
            self.assertEqual(st.progress_stats()['next_pass'],395)
            st.save_findings(ident,[],'no_signal','', 'abc',1)
            self.assertEqual(st.verified('new/one',ident),395)
            self.assertEqual(st.verified('new/one',ident),395)
            self.assertEqual(st.progress_stats()['next_pass'],396)
            st.close()
            st2=Store(path)
            self.assertEqual(st2.progress_stats()['next_pass'],396)
            self.assertEqual(st2.progress_stats()['unique_repos_total'],417)
            st2.close()

    def test_known_exclusions_have_416_unique_entries(self):
        filename=Path(__file__).resolve().parent.parent/'config'/'previously_reviewed.txt'
        assert filename.is_file()
        self.assertEqual(len(read_repos(filename)),416)

    def test_non_it_synthetic_claim_cannot_be_commercial_lead(self):
        x=analyze(Unit('Синтетический пример: Аутстафф. Заказчик: ООО «Клиент». Подрядчик: ООО «Поставщик».', 'p1'),
                  {'repo':'sample/demo','path':'demo/sample.md','url':''})
        self.assertEqual(score_lead(x)['sales_score'],0)


if __name__ == '__main__':
    unittest.main()
