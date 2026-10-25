"""High-precision, source-grounded career claims and independent sales ranking.

Every claim is tied to one bounded source block; no cross-row/company joins.
These are self-reported associations, NEVER proof of a signed contract.
"""
import html
import re
from datetime import date


def _line_bounds(text, start, end):
    return 'lines:%d-%d' % (text.count('\n', 0, start) + 1, text.count('\n', 0, end) + 1)


def profile_units(data, path):
    """Additional source units for structured TS/JS CVs and HTML timeline entries.

    A generic 1,800-character parser chunk can split company.name from
    isOutstaff:true. We only join fields within a *single* typed experience or
    a *single* HTML timeline__item block and preserve an actual line range.
    """
    if not path.lower().endswith(('.ts', '.tsx', '.js', '.jsx', '.htm', '.html')):
        return []
    text = data.decode('utf-8-sig', 'replace')
    findings = []
    # Each declaration is independent; never match values from a later employer.
    headers = list(re.finditer(r'\bconst\s+[A-Za-z_$][\w$]*\s*:\s*TExperience\s*=\s*\{', text))
    for i, match in enumerate(headers):
        end = headers[i+1].start() if i + 1 < len(headers) else min(len(text), match.start()+8000)
        block = text[match.start():end]
        if len(block) > 10000 or not re.search(r'\bisOutstaff\s*:\s*true\b', block, re.I):
            continue
        company = re.search(r'\bcompany\s*:\s*\{[^{}]{0,1100}?\bname\s*:\s*([\'"`])([^\'"`\r\n]{2,100})\1', block, re.I|re.S)
        if not company:
            continue
        customer = company.group(2).strip()
        # The input is a self-authored CV, not contractual proof.
        excerpt = block[:6500]
        findings.append(dict(text=excerpt,locator=_line_bounds(text, match.start(), min(len(text),match.start()+len(excerpt))),
                             profile_customer=customer))
    # Portfolio timeline item: explicit per-employer supplier statement, not a logo list.
    for match in re.finditer(r'<div\s+class=["\']timeline__item[^"\']*["\'][^>]*>', text, re.I):
        # Ending at the next item (or reasonable safe boundary) prevents sibling cross-joins.
        next_match = re.search(r'<div\s+class=["\']timeline__item[^"\']*["\'][^>]*>', text[match.end():],re.I)
        end = match.end()+next_match.start() if next_match else min(len(text), match.start()+9000)
        if end-match.start()>10000:
            end=match.start()+10000
        block=text[match.start():end]
        company = re.search(r'class=["\']timeline__company["\'][^>]*>([^<]{2,100})<', block, re.I)
        role = re.search(r'class=["\']timeline__role["\'][^>]*>([^<]{5,250})<', block, re.I)
        if not (company and role):
            continue
        role_text=html.unescape(role.group(1))
        supplier=re.search(r'\bvia\s+([A-Z][\w.\-& ]{1,70}?)\s+(?:IT\s+)?outstaff(?:ing)?\b',role_text,re.I)
        if not supplier:
            continue
        buyer=html.unescape(company.group(1)).strip()
        vendor=supplier.group(1).strip(' -–—')
        if not (2<=len(buyer)<=100 and 2<=len(vendor)<=70):
            continue
        # Only raw text from *this same* timeline record is the evidence.
        raw=re.sub(r'(?is)<[^>]*>',' ', block)
        raw=html.unescape(raw)
        excerpt=raw[:4500]
        findings.append(dict(text=excerpt,locator=_line_bounds(text,match.start(),end),
                             profile_customer=buyer,profile_supplier=vendor))
    return findings


def score_lead(finding):
    """Separate commercial fit from evidence reliability; not sale probability."""
    customer = bool(finding.get('customers'))
    supplier = bool(finding.get('suppliers'))
    grade=finding.get('grade','D')
    years=[int(y) for y in finding.get('years',[]) if str(y).isdigit()]
    current_year=date.today().year
    # Historic evidence stays valuable as a prospect, but not an active demand claim.
    recent = bool(years and max(years) >= current_year-1)
    geo=finding.get('geography','unknown')
    result=5 + (28 if customer else 0) + (13 if supplier else 0)
    result+= {'B':22,'C':10,'D':0,'X':-90}.get(grade,0)
    result+= 10 if finding.get('stacks') else 0
    result+= 5 if finding.get('outstaff') else 0
    result+= 10 if geo=='RF_cue' else 0
    result+= 7 if recent else 0
    result=min(100,max(0,result))
    if grade=='X':
        result=0
    if not customer:
        result=min(39,result)  # suppliers/marketing are not direct buyers
    if customer and supplier:
        relationship='self_reported_pair' if grade=='B' else 'unverified_pair'
    elif customer:
        relationship='buyer_no_named_supplier'
    else:
        relationship='no_identified_buyer'
    return dict(sales_score=result,relationship_status=relationship,
                demand_current_confirmed=False,
                sales_lane=('direct_buyer' if customer else 'supplier_partner_or_context'))
