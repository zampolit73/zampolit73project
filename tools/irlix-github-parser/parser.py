#!/usr/bin/env python3
"""IRLIX GitHub Outstaff Intelligence parser. Free, resumable, stdlib-only MVP.

GitHub Search API is a discovery source, not independent evidence of a contract.
A pass is recorded only after a previously unseen repository's real file was read.
No raw CV/files, personal contacts, API secrets, or private data are persisted.
"""
from __future__ import annotations

import argparse
import base64
import csv
import datetime as dt
import io
import json
import os
import re
import sqlite3
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from pathlib import Path

BASE = "https://api.github.com"
USER_AGENT = "IRLIX-GitHub-Research-MVP/0.1 (public-research; respectful-API-usage)"
DEFAULT_DB = "runtime/irlix-github-parser.sqlite"
DEFAULT_ARCHIVE = "data/github-outstaff-intelligence/snapshots/github_outstaff_intelligence_handoff_394_2026-10-26.zip"
FIELDS = ["pass_number", "repo", "path", "class", "type", "summary", "not_proven", "period", "sales_action", "score", "source_url", "external_source"]
BUYERS = [
    "СберТех", "Сбербанк", "СберМаркет", "Сбер", "ВТБ", "Альфа-Банк",
    "Газпромбанк", "Промсвязьбанк", "ПСБ", "Росбанк", "Совкомбанк", "Уралсиб",
    "Т-Банк", "Тинькофф", "МТС Банк", "МТС", "Открытие", "АК Барс", "Иннотех",
    "Ситидрайв", "Мегамаркет", "Магнит", "X5 Tech", "Пятёрочка", "Перекрёсток",
    "Wildberries", "Ozon", "Яндекс", "VK", "ВКонтакте", "Avito", "Авито",
    "2ГИС", "Брусника", "Золотое Яблоко", "ВкусВилл", "Cloud.ru", "Росатом",
    "СИБУР", "Ростелеком", "ЦРПТ", "Честный знак", "FITSERVICE", "Xsolla",
    "СберЗдоровье", "М Тех", "Главгосэкспертиза", "УК Аэропорты Регионов",
]
VENDORS = ["RedLab", "Ред Лаб", "Elgrow", "SkillStaff", "Selecty", "IT One", "IT-One", "Aston",
           "iFellow", "IBS", "ЛАНИТ", "Bell Integrator", "Globус IT", "Глобус ИТ", "Extyl",
           "KORONATECH", "DataArt", "EPAM", "Andersen", "Forbytes", "Neoflex"]
TECH = ["Java", "Python", "React", "Vue", "Angular", "Golang", "Go", "C#", ".NET",
        "DevOps", "QA", "тестиров", "аналитик", "backend", "frontend", "1С", "Kotlin",
        "Swift", "Kafka", "Spring", "TypeScript", "Flutter", "PHP", "Django", "PostgreSQL"]
DIRECT = re.compile(r"аутста[фf]|outstaff|staff[ -]?augmentation|внешн(?:ие|их) (?:it|ит)[ -]?специалист", re.I)
PURCHASE = re.compile(r"закупк|тендер|договор|контракт|procurement|tender|contract|vendor|поставщик|подрядчик", re.I)
NONIT = re.compile(r"грузчик|кассир|уборщик|вахтов|курьер|клининг|warehouse staff|construction worker", re.I)
EMAIL = re.compile(r"\b[\w.+%-]+@[\w.-]+\.[A-Za-zА-Яа-я]{2,}\b")
PHONE = re.compile(r"(?<!\d)(?:\+7|8)[\s()\-]*\d{3}[\s()\-]*\d{3}[\s()\-]*\d{2}[\s()\-]*\d{2}(?!\d)")
TG_HANDLE = re.compile(r"(?<![\w@])@[a-zA-Z][a-zA-Z0-9_]{4,}\b")
DATE_RANGE = re.compile(r"\bcreated:(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})\b")


class ApiError(Exception):
    def __init__(self, code, message):
        self.code, self.message = code, message
        super().__init__(f"GitHub API HTTP {code}: {message[:220]}")


class RateLimited(Exception):
    pass


def utcnow():
    return dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds")


def safe_repo(repo):
    if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repo.strip()):
        raise ValueError(f"Invalid owner/repo: {repo!r}")
    return repo.strip()


def sanitize(s):
    s = EMAIL.sub("[email redacted]", s)
    s = PHONE.sub("[phone redacted]", s)
    s = TG_HANDLE.sub("[handle redacted]", s)
    return re.sub(r"\s+", " ", s).strip()


def contains_term(text, term):
    return bool(re.search(r"(?<!\w)" + re.escape(term) + r"(?!\w)", text, re.I))


def find_names(text, choices):
    return [term for term in choices if contains_term(text, term)]


def analyze(repo, path, content):
    """Deterministic, conservative pre-screen. NEVER assigns confirmed A or B."""
    raw = content[:200_000]
    strong = list(DIRECT.finditer(raw))[:15]
    buyers, vendors = find_names(raw, BUYERS), find_names(raw, VENDORS)
    stacks = find_names(raw, TECH)
    purchase = bool(PURCHASE.search(raw))
    nonit = bool(NONIT.search(raw)) and not any(contains_term(raw, x) for x in TECH)
    # Snippets must be attached to real content, not search results.
    snippets = []
    for m in strong[:3]:
        start, stop = max(0, m.start() - 110), min(len(raw), m.end() + 200)
        snippets.append(sanitize(raw[start:stop])[:320])
    score = min(100, (25 if strong else 0) + (23 if buyers else 0) +
                (12 if vendors else 0) + (12 if stacks else 0) +
                (10 if purchase else 0) + (8 if "2026" in raw else 0))
    if nonit:
        score = min(score, 5)
    if strong and buyers and not nonit:
        grade, kind = "C", "Кандидат: заказчик и аутстафф (не проверен)"
        action = "Проверить первичный источник, договорную модель и действующий спрос."
    elif strong and vendors and not nonit:
        grade, kind = "C", "Поставщик IT-аутстаффа / вероятный партнёр"
        action = "Проверить клиентов поставщика и возможности субподряда."
    elif strong and stacks and not nonit:
        grade, kind = "C", "Упоминание IT-аутстаффа без установленного покупателя"
        action = "Проверить сущности, роли и конечного заказчика вручную."
    elif nonit:
        grade, kind, action = "X", "Вероятный не-IT аутстафф", "Исключить из IT-лидов."
    else:
        grade, kind, action = "D", "Слабый / справочный сигнал", "Не считать доказательством закупки."
    if not snippets:
        # Keep limited context only; never a raw dump of CV, contacts, or source code.
        snippets = [sanitize(raw[:220])] if strong or buyers or vendors else []
    result = {
        "class": grade, "type": kind, "score": score,
        "buyers": ", ".join(buyers[:8]), "vendors": ", ".join(vendors[:8]),
        "stacks": ", ".join(stacks[:10]),
        "summary": (" | ".join(snippets))[:850] or "Именованный IT-аутстафф-покупатель не установлен.",
        "not_proven": "Нет подтверждённого договора/актуальной закупки; требуется проверка специалистом.",
        "period": "2026 упомянут в документе (не дата сделки)" if "2026" in raw else "Дата сделки не установлена",
        "sales_action": action,
        "needs_review": int(grade == "C" and bool(buyers)),
    }
    return result


def connect(db_path):
    path = Path(db_path)
    path.parent.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(path)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA journal_mode=WAL")
    con.executescript("""
    CREATE TABLE IF NOT EXISTS meta(key TEXT PRIMARY KEY, value TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS repos(
      repo_key TEXT PRIMARY KEY, repo TEXT NOT NULL, status TEXT NOT NULL,
      pass_number INTEGER UNIQUE, path TEXT, class TEXT, type TEXT,
      summary TEXT, not_proven TEXT, period TEXT, sales_action TEXT,
      score INTEGER, source_url TEXT, external_source TEXT,
      buyers TEXT, vendors TEXT, stacks TEXT, needs_review INTEGER DEFAULT 0,
      checked_at TEXT, sha TEXT
    );
    CREATE TABLE IF NOT EXISTS jobs(
      id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, query TEXT NOT NULL,
      page INTEGER NOT NULL DEFAULT 1, offset INTEGER NOT NULL DEFAULT 0,
      status TEXT NOT NULL DEFAULT 'pending', note TEXT DEFAULT '',
      UNIQUE(kind,query)
    );
    CREATE TABLE IF NOT EXISTS skips(
      repo_key TEXT PRIMARY KEY, reason TEXT, last_attempt TEXT NOT NULL
    );
    """)
    con.commit()
    return con


def meta_get(con, k, default=None):
    r=con.execute("SELECT value FROM meta WHERE key=?", (k,)).fetchone()
    return r[0] if r else default


def meta_set(con, k, v):
    con.execute("INSERT INTO meta(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", (k, str(v)))


def init_from_archive(con, archive):
    """Import the exact previously verified exclusions and last pass number."""
    if meta_get(con, "initialized"):
        raise RuntimeError("This database is already initialized. Use scan/export/stats instead.")
    with zipfile.ZipFile(archive) as z:
        sn = next(x for x in z.namelist() if "state_" in x and x.endswith(".json"))
        snapshot = json.loads(z.read(sn))
        repos = snapshot["unique_repositories_from_workbook"]
        last_pass = int(snapshot["status"]["currently_saved_last_pass"])
        more = int(snapshot["status"]["additional_repos_since_65_reported"])
        if len(set(x.casefold() for x in repos)) != len(repos):
            raise ValueError("Archived exclusion register has duplicates")
        for name in repos:
            name = safe_repo(name)
            con.execute("INSERT OR IGNORE INTO repos(repo_key,repo,status) VALUES(?,?,'legacy')", (name.casefold(), name))
        meta_set(con, "initialized", utcnow())
        meta_set(con, "initial_repo_count", len(repos))
        meta_set(con, "initial_last_pass", last_pass)
        meta_set(con, "next_pass", last_pass+1)
        meta_set(con, "initial_additional_count", more)
        meta_set(con, "target_additional_count", 50000)
    con.commit()
    return len(repos), last_pass


class GithubClient:
    def __init__(self, token=None, pace=None):
        self.token = token or os.environ.get("GITHUB_TOKEN")
        self.last = {}
        self.pace = pace

    def get(self, endpoint, params=None):
        kind = "code" if endpoint.startswith("/search/code") else "search" if endpoint.startswith("/search/") else "core"
        delay = self.pace if self.pace is not None else (6.5 if kind=="code" or (kind=="search" and not self.token) else 2.2 if kind=="search" else 0.18)
        waited = time.monotonic()-self.last.get(kind, 0)
        if waited < delay:
            time.sleep(delay-waited)
        url = BASE + endpoint + (("?"+urllib.parse.urlencode(params)) if params else "")
        headers = {"User-Agent":USER_AGENT,"Accept":"application/vnd.github+json","X-GitHub-Api-Version":"2022-11-28"}
        if self.token:
            headers["Authorization"] = "Bearer "+self.token
        req = urllib.request.Request(url,headers=headers)
        self.last[kind] = time.monotonic()
        try:
            with urllib.request.urlopen(req, timeout=35) as response:
                data = response.read(6_000_000)
                return json.loads(data)
        except urllib.error.HTTPError as e:
            message = e.read(500).decode("utf-8","replace")
            remaining = e.headers.get("X-RateLimit-Remaining")
            if e.code in (403,429) and (remaining == "0" or "rate limit" in message.lower()):
                reset=e.headers.get("X-RateLimit-Reset","unknown")
                retry=e.headers.get("Retry-After","unknown")
                raise RateLimited(f"GitHub rate limit; reset epoch={reset}, retry-after={retry}. Stop and resume later.") from e
            raise ApiError(e.code,message) from e
        except (TimeoutError, urllib.error.URLError) as e:
            raise RuntimeError(f"Network error, cursor retained: {e}") from e

    def search(self, kind, query, page):
        if kind=="code" and not self.token:
            raise RuntimeError("GitHub code search requires GITHUB_TOKEN")
        return self.get("/search/"+("code" if kind=="code" else "repositories"),
                        {"q":query,"per_page":100,"page":page})

    def file(self, repo, path=None):
        safe_repo(repo)
        prefix = "/repos/" + repo + ("/contents/" + urllib.parse.quote(path,safe="/") if path else "/readme")
        response = self.get(prefix)
        if not isinstance(response,dict) or response.get("type") not in (None,"file"):
            return None
        if response.get("encoding")!="base64" or not response.get("content"):
            return None
        try:
            content=base64.b64decode(response["content"], validate=False).decode("utf-8", "replace")
        except (ValueError, UnicodeError):
            return None
        if not content.strip():
            return None
        actual_path = response.get("path", path or "README.md")
        source = response.get("html_url") or "https://github.com/"+repo+"/blob/HEAD/"+urllib.parse.quote(actual_path)
        return {"content":content,"path":actual_path,"url":source,"sha":response.get("sha","")}


def seeded_queries():
    terms = ["аутстафф", "аутстаффинг", "outstaff", "outstaffing", "staff augmentation", "IT outsourcing"]
    for year in range(2026, 2018, -1):
        for quarter in range(4,0,-1):
            start_month=(quarter-1)*3+1
            start=dt.date(year,start_month,1)
            end_month=start_month+2
            end=(dt.date(year+1,1,1) if end_month==12 else dt.date(year,end_month+1,1))-dt.timedelta(days=1)
            for term in terms:
                yield "repositories", f'"{term}" in:readme created:{start}..{end}'
    for year in range(2018,2008,-1):
        for term in terms:
            yield "repositories", f'"{term}" in:readme created:{year}-01-01..{year}-12-31'
    code_queries = ['"аутстафф" extension:md', '"аутстафф" extension:html',
                    '"аутстафф" extension:json', '"аутстафф" extension:txt',
                    '"outstaff" extension:md', '"outstaff" extension:json',
                    '"outstaff" extension:tsx', '"staff augmentation" extension:md']
    for q in code_queries:
        yield "code",q


def seed_jobs(con, mode="hybrid", has_token=False):
    enabled={"repositories"} if mode=="repo" else ({"code"} if mode=="code" else {"repositories","code"})
    if "code" in enabled and not has_token:
        if mode=="code":
            raise RuntimeError("Code mode requires GITHUB_TOKEN in the environment")
        enabled.remove("code")
    for kind, q in seeded_queries():
        if kind in enabled:
            con.execute("INSERT OR IGNORE INTO jobs(kind,query) VALUES(?,?)",(kind,q))
    con.commit()


def split_job_if_capped(con, job, n):
    if n<=900 or job["kind"]!="repositories":
        return False
    match=DATE_RANGE.search(job["query"])
    if not match:return False
    start,end=map(dt.date.fromisoformat,match.groups())
    if start>=end:return False
    half=start+dt.timedelta(days=(end-start).days//2)
    for a,b in [(start,half),(half+dt.timedelta(days=1),end)]:
        child=job["query"][:match.start()]+f"created:{a}..{b}"+job["query"][match.end():]
        con.execute("INSERT OR IGNORE INTO jobs(kind,query) VALUES('repositories',?)",(child,))
    con.execute("UPDATE jobs SET status='split', note=? WHERE id=?",(f"{n} results, split dates",job["id"]))
    con.commit()
    return True


def register_pass(con,repo,file,analysis):
    """Atomic monotonic pass assignment; no repo gets checked twice."""
    repo=safe_repo(repo)
    key=repo.casefold()
    with con:
        if con.execute("SELECT 1 FROM repos WHERE repo_key=?",(key,)).fetchone():
            return None
        number=int(meta_get(con,"next_pass"))
        con.execute("""INSERT INTO repos(repo_key,repo,status,pass_number,path,class,type,summary,
                     not_proven,period,sales_action,score,source_url,external_source,
                     buyers,vendors,stacks,needs_review,checked_at,sha)
                     VALUES(?,?,'checked',?,?,?,?,?,?,?,?,?,?,'',?,?,?,?,?,?)""",
                    (key,repo,number,file["path"],analysis["class"],analysis["type"],analysis["summary"],
                     analysis["not_proven"],analysis["period"],analysis["sales_action"],analysis["score"],file["url"],
                     analysis["buyers"],analysis["vendors"],analysis["stacks"],analysis["needs_review"],utcnow(),file.get("sha", "")))
        meta_set(con,"next_pass",number+1)
    return number


def handle_repo(con,client,item,kind):
    if kind=="code":
        repo=item.get("repository",{}).get("full_name","")
        path=item.get("path")
    else:
        repo=item.get("full_name","")
        path=None
    try:
        repo=safe_repo(repo)
    except ValueError:
        return None
    key=repo.casefold()
    if con.execute("SELECT 1 FROM repos WHERE repo_key=?",(key,)).fetchone():
        return None
    if con.execute("SELECT 1 FROM skips WHERE repo_key=?",(key,)).fetchone():
        return None
    try:
        file=client.file(repo,path=path)
        if not file and path:  # Code-search hit may be too big or on an obsolete ref.
            file=client.file(repo)
    except ApiError as ex:
        if ex.code in (404, 403, 451):
            with con:
                con.execute("INSERT OR REPLACE INTO skips VALUES(?,?,?)",(key,str(ex)[:190],utcnow()))
            return None
        raise
    if not file:
        with con:
            con.execute("INSERT OR REPLACE INTO skips VALUES(?,?,?)",(key,"Unreadable/empty/large/non-UTF8 file",utcnow()))
        return None
    analysis=analyze(repo,file["path"],file["content"])
    number=register_pass(con,repo,file,analysis)
    if number:
        print(f"  #{number} {repo}: {analysis['class']}, {analysis['score']}/100 "
              f"[{analysis['buyers'] or analysis['vendors'] or 'no named buyer'}]",flush=True)
    return number


def scan(con,client,max_repos=50,mode="hybrid",max_api_pages=None):
    if not meta_get(con,"initialized"):
        raise RuntimeError("Initialize via `init --archive ...` before scanning")
    seed_jobs(con,mode,bool(client.token))
    count,pages=0,0
    enabled=("repositories",) if mode=="repo" or not client.token else ("code",) if mode=="code" else ("code","repositories")
    while count < max_repos:
        job=con.execute("SELECT * FROM jobs WHERE status='pending' AND kind IN ("+",".join("?" for _ in enabled)+") ORDER BY CASE WHEN kind='code' THEN 0 ELSE 1 END, id LIMIT 1",enabled).fetchone()
        if not job:
            print("No unprocessed queries left. Expand queries or new source families.")
            break
        page,off=int(job["page"]),int(job["offset"])
        if page>10: # GitHub Search API exposes at most 1000 items per query.
            with con:con.execute("UPDATE jobs SET status='done',note='Search API 1000 result window' WHERE id=?",(job["id"],))
            continue
        try:
            result=client.search(job["kind"],job["query"],page)
        except ApiError as ex:
            if ex.code in (400,422):
                with con:con.execute("UPDATE jobs SET status='invalid',note=? WHERE id=?",(str(ex)[:300],job["id"]))
                print(f"Skipping invalid GitHub query: {job['query']}")
                continue
            raise
        pages+=1
        results=result.get("items") or []
        if page==1 and off==0 and split_job_if_capped(con,job,int(result.get("total_count",0))):
            continue
        if not results or off>=len(results):
            with con:
                con.execute("UPDATE jobs SET page=?,offset=0,status=?,note=? WHERE id=?",
                            (page+1,"done" if not results or page>=10 or len(results)<100 else "pending",
                             "Search truncated (>1000)" if page>=10 else "",job["id"]))
            if max_api_pages and pages>=max_api_pages:break
            continue
        while off<len(results) and count<max_repos:
            item=results[off]
            # Keep the cursor on the same item if a network/rate error interrupts processing.
            number=handle_repo(con,client,item,job["kind"])
            with con:
                con.execute("UPDATE jobs SET offset=? WHERE id=?",(off+1,job["id"]))
            off+=1
            if number: count+=1
        if off==len(results):
            with con:
                con.execute("UPDATE jobs SET page=?,offset=0,status=?,note=? WHERE id=?",
                            (page+1,"done" if page>=10 or len(results)<100 else "pending",
                             "Search truncated (>1000)" if page>=10 else "",job["id"]))
        if max_api_pages and pages>=max_api_pages:break
    return count,pages


def export(con,out_dir):
    if not meta_get(con,"initialized"):raise RuntimeError("Database not initialized")
    out=Path(out_dir);out.mkdir(parents=True,exist_ok=True)
    rows=con.execute("SELECT * FROM repos WHERE status='checked' ORDER BY pass_number").fetchall()
    def write(name,selected,columns):
        p=out/name
        with p.open("w",encoding="utf-8-sig",newline="") as f:
            w=csv.writer(f);w.writerow(columns)
            for x in selected:w.writerow([x[col] for col in columns])
        return p
    a=write("checked_repos.csv",rows,FIELDS)
    leads=[x for x in rows if x["needs_review"]==1]
    leads.sort(key=lambda x:(x["score"] or 0),reverse=True)
    b=write("sales_review_queue.csv",leads,FIELDS+["buyers","vendors","stacks","checked_at"])
    c=out/"excluded_repositories.txt"
    c.write_text("\n".join(x[0] for x in con.execute("SELECT repo FROM repos ORDER BY repo_key"))+"\n",encoding="utf-8")
    stat=stats(con)
    d=out/"STATUS.md"
    d.write_text("# IRLIX GitHub Outstaff parser — local checkpoint\n\n"+
                 "\n".join(f"- **{k}**: {v}" for k,v in stat.items())+
                 "\n\nAutomated classifications are only hypotheses; none is proof of a signed contract.\n"
                 "No raw file contents, private contacts or tokens are exported.\n",encoding="utf-8")
    return [a,b,c,d]


def stats(con):
    old=int(meta_get(con,"initial_repo_count",0))
    initial_more=int(meta_get(con,"initial_additional_count",0))
    new=con.execute("SELECT COUNT(*) FROM repos WHERE status='checked'").fetchone()[0]
    return {"archived_unique_repos":old, "new_file_verified_repos":new,
            "unique_repos_total":con.execute("SELECT COUNT(*) FROM repos").fetchone()[0],
            "last_pass_number":int(meta_get(con,"next_pass",1))-1,
            "next_pass_number":int(meta_get(con,"next_pass",1)),
            "target_50000_completed":initial_more+new,
            "target_50000_remaining":max(0,50000-initial_more-new),
            "needs_human_review":con.execute("SELECT COUNT(*) FROM repos WHERE status='checked' AND needs_review=1").fetchone()[0],
            "pending_search_queries":con.execute("SELECT COUNT(*) FROM jobs WHERE status='pending'").fetchone()[0],
            "unreadable_skipped_repos":con.execute("SELECT COUNT(*) FROM skips").fetchone()[0]}


def main(argv=None):
    p=argparse.ArgumentParser(description="Free GitHub buyer/outstaff research parser (stdlib-only)")
    p.add_argument("--db",default=DEFAULT_DB,help="Local SQLite state, never commit to public Git")
    sub=p.add_subparsers(dest="cmd",required=True)
    init=sub.add_parser("init",help="Bootstrap historical exclusion list and counters from ZIP checkpoint")
    init.add_argument("--archive",default=DEFAULT_ARCHIVE)
    run=sub.add_parser("scan",help="Scan new repositories; persistent resume after each fully read file")
    run.add_argument("--max-repos",type=int,default=50)
    run.add_argument("--max-api-pages",type=int,default=None,help="Useful for small smoke tests")
    run.add_argument("--mode",choices=["hybrid","repo","code"],default="hybrid")
    ex=sub.add_parser("export",help="Write Excel-compatible CSV, exclusion registry, sales review queue")
    ex.add_argument("--out",default="runtime/exports")
    sub.add_parser("stats")
    args=p.parse_args(argv)
    try:
        con=connect(args.db)
        if args.cmd=="init":
            n,number=init_from_archive(con,args.archive)
            print(f"Imported {n} unique checked repos; next pass #{number+1}.")
        elif args.cmd=="scan":
            if args.max_repos<1: p.error("--max-repos must be positive")
            client=GithubClient()
            print("Mode:",args.mode,"Token:",bool(client.token),"(no credential is stored)")
            count,pages=scan(con,client,args.max_repos,args.mode,args.max_api_pages)
            print(f"Completed {count} new file-verified repos, used {pages} GitHub Search pages.")
            print(json.dumps(stats(con),ensure_ascii=False,indent=2))
        elif args.cmd=="export":
            for fn in export(con,args.out):print(fn)
        else:
            print(json.dumps(stats(con),ensure_ascii=False,indent=2))
        return 0
    except (RateLimited,RuntimeError,ApiError,ValueError,FileNotFoundError) as ex:
        print("Stopped safely:",ex,file=sys.stderr)
        return 2


if __name__=="__main__":
    raise SystemExit(main())
