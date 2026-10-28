# Professional tax and labour welfare fund by state. Revised 2026-10-27 from verify-pt.md / verify-lwf.md (official
# sources read where reachable; labels as in india-compliance-source-register.md).
PV = '2026-10-27'
_P = 'OFFICIAL_PRIMARY_SOURCE_VERIFIED'
_PART = 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED'
_SEC = 'SECONDARY_SOURCE_ONLY'

def pt(act, frequency, due, due_text, form, confidence, frequency_text, basis=None, note='', levied=True, sources=(), notes=(), legal_review=None, effective_to=None):
    allnotes = [note] if note else []
    allnotes += list(notes)
    return dict(levied=levied, act=act, frequency=frequency, basis=basis, due=due, due_text=due_text, form=form, confidence=confidence,
                frequency_text=frequency_text, note=note, notes=allnotes, sources=[list(x) for x in sources], verified_on=PV, legal_review=legal_review, effective_to=effective_to)

def none(act, note, confidence=None):
    d = dict(levied=False, act=act, frequency_text='—', due_text='—', form='—', note=note)
    if confidence:
        d['confidence'] = confidence
    return d

PT = {
    'MH': pt('Maharashtra State Tax on Professions, Trades, Callings and Employments Act, 1975 (as amended by Mah. Act XIX of 2023) and Rules, 1975', 'monthly', {'months_after': 1, 'day': 15},
             'Form III-B e-return and MTR-6 payment by the 15th of the next month (Amendment Rules of 28 Feb 2026; earlier the last day of the month); annual filers by 15 March', 'Form III-B e-return with MTR-6 challan', _P,
             'Monthly if the previous year\'s liability was ₹1,00,000 or more (and in the registration year); otherwise annual',
             note='Men: nil to ₹7,500; ₹175 to ₹10,000; ₹200 above (₹300 in February). Women: nil to ₹25,000. Employers below the monthly threshold file one annual return: use a yearly pack.',
             sources=[('Maharashtra GST Department — PT rate schedule', 'https://www.mahagst.gov.in/public/uploads/menufiles/PT%20Rate%20Schedule%20updated%20upto%2031.03.2025%20(1).pdf'), ('PT notification of 28-02-2026 (due dates)', 'https://www.mahagst.gov.in/public/uploads/mvatservices/1772710759_PT%20Notification%2028.2.2026.pdf')]),
    'KA': pt('Karnataka Tax on Professions, Trades, Callings and Employments Act, 1976 (Acts 14 of 2023 and 33 of 2025)', 'monthly', {'months_after': 1, 'day': 20},
             'Form 5-A statement and payment within 20 days after the month end (s.6-A(1)); annual Form 5', 'Form 5-A (monthly); Form 5 (annual)', _P, 'Monthly, with an annual return',
             note='Nil below ₹25,000 a month (from 1 Apr 2023); ₹200 above, ₹300 in February only from 1 Apr 2025.',
             sources=[('Karnataka Commercial Taxes — PT for employers', 'https://ptax.karnataka.gov.in/ptemployer'), ('Karnataka PT amendment notification', 'https://gst.karnataka.gov.in/Documents/General/ptnotificationact29323.pdf')],
             legal_review='The rule pack\'s Karnataka version charges ₹300 in February from 2023; February 2024 and 2025 were ₹200 (see the rule-change register).'),
    'TN': pt('Tamil Nadu Urban Local Bodies Act, 1998 ss.117-B, 117-C; TN Urban Local Bodies Rules, 2023 rr.277–278 (professional tax by the local body)', 'half_yearly', {'months_after': 0, 'day': 'end'},
             'deducted from the August and January pay bills; paid with Form 14 by 30 September and 31 March (r.277(2)–(3))', 'Form 14 (with remittance); Form 15 (employee-wise return)', _PART,
             'Half-yearly (April–September, October–March)', basis='financial',
             note='Greater Chennai Corporation from 1 Oct 2024, per half-year: nil to ₹21,000; ₹180; ₹425; ₹930; ₹1,025; ₹1,250 above ₹75,000.',
             notes=['Band chosen on the average half-yearly income of the previous financial year (r.274(a)); bonus and gratuity are excluded from salary (r.274(e)). Where a council has made no general revision, the r.275(2) default table applies: nil to ₹21,000; ₹135; ₹315; ₹690; ₹1,025; ₹1,250.'],
             sources=[('Tamil Nadu Urban Local Bodies Rules, 2023 — G.O. Ms. No. 45, MAWS, 12-04-2023, rr.274–282 (gazette print on a tn.gov.in host)', 'https://tnurbantree.tn.gov.in/sivagangai/wp-content/uploads/sites/38/2023/07/2023-Service-Rules-404-pages.pdf'), ('Greater Chennai Corporation — Revenue (professional tax)', 'https://chennaicorporation.gov.in/gcc/department/revenue/'), ('Greater Chennai Corporation press release of 04-08-2026', 'https://chennaicorporation.gov.in/gcc/pdf/PT_Press_Release.pdf')],
             legal_review='Greater Chennai Corporation\'s 2024 rates come from its rate page, not the council resolution; other local bodies\' revisions and panchayat levies are not confirmed. The rule pack\'s Tamil Nadu version deducts monthly on the r.275(2) table: wrong structure for every local body and wrong amounts in Chennai since October 2024.'),
    'TG': pt('Telangana Tax on Professions, Trades, Callings and Employments Act, 1987 and Rules', 'monthly', {'months_after': 1, 'day': 10},
             'Form V return and payment by the 10th of the next month (due date from secondary sources)', 'Form V', _PART, 'Monthly',
             sources=[('Telangana Commercial Taxes — PT schedule', 'https://www.tgct.gov.in/tgportal/AllActs/APPT/APPTSchedule.aspx'), ('Telangana PT Rules', 'https://www.tgct.gov.in/tgportal/AllActs/APPT/APPTRules.aspx')]),
    'AP': pt('Andhra Pradesh Tax on Professions, Trades, Callings and Employments Act, 1987 (amended by Act 12 of 2013) and Rules', 'monthly', {'months_after': 1, 'day': 10},
             'Form V and payment by the 10th of the next month (the date is from secondary sources: the Act and Rules leave it to be prescribed)', 'Form V', _PART, 'Monthly',
             note='Nil up to ₹15,000; ₹150 to ₹20,000; ₹200 above (G.O.Ms.No. 82, Revenue (CT-IV), 04-02-2013, gazette of 06-02-2013).',
             sources=[('Andhra Pradesh Commercial Taxes — PT First Schedule (as amended up to date)', 'https://apvatisold.apct.gov.in/apportal/AllActs/APPT/APPTSchedule.aspx'), ('Andhra Pradesh PT Act, 1987 (PRS copy)', 'https://prsindia.org/files/bills_acts/acts_states/andhra-pradesh/1987/1987AP22.pdf')],
             legal_review='The employer\'s monthly due date is not in any official text read.'),
    'GJ': pt('Gujarat Panchayats, Municipalities, Municipal Corporations and State Tax on Professions, Traders, Callings and Employments Act, 1976; Rules, 1976', 'monthly', {'months_after': 1, 'day': 15},
             'more than 20 employees: Form 5 within 15 days after the month end; 20 or fewer: quarterly payment and Form 5-AA within 15 days after the year end', 'Form 5 (monthly); Form 5-AA (annual, 20 or fewer employees)', _PART,
             'Monthly above 20 employees; quarterly payment and an annual return for 20 or fewer', note='Nil up to ₹12,000 a month, then ₹200 (from 1 Apr 2022, per the department\'s rate schedule; the 2022 notification itself was not found on an official host).',
             sources=[('Gujarat Commercial Tax — rate of profession tax', 'https://commercialtax.gujarat.gov.in/vatwebsite/download/schedule/Rate_of_Profession_Tax.pdf'), ('Gujarat Professions Tax Rules', 'https://commercialtax.gujarat.gov.in/vatwebsite/download/rule/Ptrules.pdf')]),
    'WB': pt('West Bengal State Tax on Professions, Trades, Callings and Employments Act, 1979 (amended by the WB Finance Act, 2026)', 'monthly', {'months_after': 1, 'day': 21},
             'paid within 21 days after the month (r.12); Form III return annually by 30 April where the year\'s tax is ₹30,000 or less, otherwise quarterly (2008 consolidation)', 'Form III', _PART, 'Monthly payment; annual or quarterly return',
             note='From 1 Oct 2026 (draft 1407-F.T., finalised as 1607-F.T. per secondary sources): nil up to ₹20,000; ₹100; ₹140; ₹170; ₹208 above ₹1,00,000. Before: nil up to ₹10,000, top rate ₹200.',
             sources=[('WB schedule effective 1-4-2014 (Commercial Taxes)', 'https://comtax.wb.gov.in/Ptax-Schedule-New_(w.e.f._1-4-2014).pdf'), ('WB Finance Act, 2026 (Act XIII of 2026), notification 756-L of 07-08-2026', 'https://comtax.wb.gov.in/GST/GST_Acts_Rules/756L_20260820.pdf'),
                      ('Commencement notification 1406-F.T. of 18-08-2026 (s.2(1), (2)(a), (4) from 1 Oct 2026)', 'https://comtax.wb.gov.in/GST/GST_Acts_Rules/1406-FT_20260820.pdf'), ('Draft schedule 1407-F.T. of 18-08-2026', 'https://comtax.wb.gov.in/pdf/SAR-470_Finance%20Dept(Rev)_1407-FT.pdf')],
             legal_review='The final schedule 1607-F.T. was not read officially; its own dates decide when the new slabs apply (1406-F.T. clause (c)).'),
    'KL': pt('Kerala Panchayat Raj Act, 1994 ss.204–205H and Kerala Municipality Act, 1994 (professional tax by the local body); G.O.(Rt) No. 1149/2024/LSGD', 'half_yearly', {'months_after': -1, 'day': 'end'},
             'assessed and paid before the end of August and of February (Panchayat Raj Act s.205F(2); municipalities not confirmed)', 'Requisition-and-demand cycle (Panchayat Raj PT Rules Forms I–V)', 'LEGAL_REVIEW_REQUIRED', 'Half-yearly (April–September, October–March)', basis='financial',
             note='From 1 Oct 2024, per half-year: nil to ₹11,999; ₹320; ₹450; ₹600; ₹750; ₹1,000; ₹1,250 from ₹1,25,000. HRA, CCA, conveyance and travelling allowance are excluded from income.',
             sources=[('G.O.(Rt) No. 1149/2024/LSGD of 27-06-2024', 'https://go.lsgkerala.gov.in/files/go20240627_37169.pdf'), ('Kerala Panchayat Raj Act s.205F (LSGD law manual)', 'https://panchayatwiki.lsgkerala.gov.in/Panchayat:Repo18/Law_Manual_Page0264')],
             legal_review='Panchayat Raj Act s.204(2): rates are "fixed by the Village Panchayat not exceeding the maximum rates prescribed". No amendment of the Profession Tax Rules setting the 2024 rates as maximums was found, nor council resolutions adopting them. The municipal provisions (s.245) and due dates were not read officially.'),
    'MP': pt('Madhya Pradesh Vritti Kar Adhiniyam, 1995 (Schedule substituted 2018); Vritti Kar Niyam, 1995', 'monthly', {'months_after': 1, 'day': 10},
             'paid within 10 days after the month end (s.12(2)); Form 7 quarterly return by the 15th of the month after each quarter (r.11(1))', 'Form 7 (quarterly return)', _P, 'Monthly payment, quarterly return',
             note='Bands on annual salary: nil to ₹2,25,000; ₹1,500; ₹2,000; ₹2,500 a year — monthly ₹125; ₹166 (₹174 in the 12th month); ₹208 (₹212 in the 12th month).',
             sources=[('MP Vritti Kar (Amendment) Act, 2018', 'https://mptax.mp.gov.in/mpvatweb/download/act/pdf/PT-Amendment-Act-2018.pdf'), ('MP Vritti Kar Act and Rules', 'https://mptax.mp.gov.in/mpvatweb/download/act/pdf/PTACTRules.pdf')],
             legal_review='The rule pack\'s Madhya Pradesh version has ₹167 for the third band (the law: ₹166, ₹174 in the 12th month).'),
    'PB': pt('Punjab State Development Tax Act, 2018; PSDT Rules, 2018 (amended 2021 and 2022)', 'monthly', {'months_after': 1, 'day': 'end'},
             'deducted monthly and deposited by the last day of the next month (r.14(3)); annual return PSDT-6 by 31 August', 'PSDT-6 (annual return); PSDT-8 (challan)', 'LEGAL_REVIEW_REQUIRED', 'Monthly deduction and deposit; annual return',
             note='Punjab levies a State Development Tax of ₹200 a month in place of professional tax, only on employees whose taxable income exceeds the income-tax exemption limit.',
             sources=[('Punjab State Development Tax Act, 2018', 'https://psdt.punjab.gov.in/docs/Punjab_State_Development_Tax_2018.pdf'), ('PSDT First Amendment Rules, 2022', 'https://psdt.punjab.gov.in/docs/PSDT_FirstAmendmentRules_2022.pdf')],
             legal_review='How the income-tax exemption-limit test works under the new regime and the Income-tax Act, 2025 needs review. PeopleOS has no Punjab rule version.'),
    'AS': pt('Assam Professions, Trades, Callings and Employments Taxation Act, 1947 and Rules', 'monthly', {'months_after': 1, 'day': 'end'},
             'Form III with the tax paid, on or before the last day of the next month (r.12(1)–(2)); quarterly, half-yearly or annual returns only with permission (r.12(3))', 'Form III; challans VII-C / VII-CC', _P, 'Monthly',
             note='From 2 Apr 2025: nil up to ₹15,000; ₹180 to ₹24,999; ₹208 above.',
             sources=[('Assam Taxes — notification of 01-04-2025', 'https://tax.assam.gov.in/AssamTimsInfo/GST/FORMATS/No.%20139%20169350-124%20Dated%2001-04-25.pdf'), ('Assam Professions Tax Rules, 1947 (department consolidation)', 'https://tax.assam.gov.in/circular/circular/apt/rules/apt.pdf')],
             notes=['Vendor guides give the 28th of the month; the Rules\' last day of the month prevails. Amendments to r.12 after 2010 were not checked.']),
    'BR': pt('Bihar Tax on Professions, Trades, Callings and Employments Act, 2011; Professional Tax Rules, 2011', 'yearly', {'months_after': -4, 'day': 30},
             'annual tax deducted from September salary and deposited by 15 November; PT-IV and PT-VIII by 30 November (rules, secondary)', 'PT-IV, PT-VIII', _PART, 'Annual', basis='financial',
             note='Annual: nil to ₹3,00,000; ₹1,000; ₹2,000; ₹2,500 above ₹10,00,000.',
             sources=[('Bihar PT Act, 2011 (PRS copy)', 'https://prsindia.org/files/bills_acts/acts_states/bihar/2011/2011Bihar10.pdf')]),
    'JH': pt('Jharkhand Tax on Professions, Trades, Callings and Employments Act, 2011; Rules, 2012', 'quarterly', {'months_after': 1, 'day': 15},
             'paid within 15 days after each quarter (s.5(1)); quarterly return JPT-201 (the Rules\' dates are inconsistent as printed); annual return JPT-203 within one month after the first six months of the year (r.12(3))', 'JPT-201 (quarterly); JPT-203 (annual)', _PART, 'Quarterly payment, quarterly and annual returns', basis='financial',
             note='Annual: nil to ₹3,00,000; ₹1,200; ₹1,800; ₹2,100; ₹2,500 above ₹10,00,000 (Act Schedule entry 1).',
             sources=[('Jharkhand PT Act, 2011 (department copy, headed "Bill")', 'https://ctax.jharkhand.gov.in/documents/10231/16291/Other+Acts+and+Rules/4191b34f-315c-43c9-9ceb-628d3c2cb46a?version=1.2'), ('Jharkhand Professional Tax Rules, 2012 (scan)', 'https://ctax.jharkhand.gov.in/documents/10231/16514/Professional+Tax+Rules%2C+2012/7462df88-a600-4f9c-a035-c92ef02678b5?version=1.1')],
             legal_review='The appointed day (commencement) was not found; r.12(1) pairs quarters with return dates inconsistently.'),
    'TR': pt('Tripura Professions, Trades, Callings and Employments Taxation Act, 1997; Rules, 1998 (amended 2021)', 'yearly', {'months_after': 1, 'day': 30},
             'deducted monthly; the year\'s tax paid into the Treasury and Form VII filed by 30 April (r.12(2))', 'Form VII', _P, 'Deducted monthly; annual return', basis='financial',
             note='Nil up to ₹7,500; ₹150 to ₹15,000; ₹208 above (from 25 Jul 2018).',
             sources=[('Tripura Taxes — PT notifications', 'https://taxes.tripura.gov.in/sites/default/files/2024-04/PTAX_NOTF_2_up.pdf'), ('Tripura Professions Act', 'https://taxes.tripura.gov.in/sites/default/files/2024-04/The%20Tripura%20Professions%20Act.pdf')]),
    'SK': pt('Sikkim Tax on Professions, Trades, Callings and Employments Act, 2006; Rules, 2006', 'quarterly', {'months_after': 1, 'day': 'end'},
             'paid within 15 days after each month (r.13(1)); Form 5 quarterly return by the end of the month after each quarter', 'Form 5', _PART, 'Monthly payment, quarterly return', basis='financial',
             note='Nil up to ₹20,000; ₹125; ₹150; ₹200 above ₹40,000 (the gazette column is headed "Rate of tax (Proposal)").',
             sources=[('Sikkim PT Act, 2006 — Gazette Extraordinary No. 95, 1 Apr 2006 (PRS print)', 'https://prsindia.org/files/bills_acts/acts_states/sikkim/2006/2006SK5.pdf')],
             notes=['The department host (sikkimtax.gov.in) offers no modern TLS, so its copies of the rate schedule and the Rules could not be read securely; r.13(1) was read only over unencrypted HTTP.'],
             legal_review='The appointed day (s.1(3)) is not confirmed.'),
    'NL': pt('Nagaland Professions, Trades, Callings and Employments Taxation Act, 1968 (4th amendment 2012); Rules, 1970', 'monthly', None,
             'monthly Form V return with the tax paid by challan; the monthly due day is not confirmed', 'Form V', _PART, 'Monthly',
             note='Nil up to ₹3,999; ₹35; ₹75; ₹110; ₹180; ₹208 from ₹12,000.',
             sources=[('Nagaland Taxes — PT public notice', 'https://nagalandtax.nic.in/docs/P_TAX_Public_Notice.pdf')]),
    'MZ': pt('Mizoram Professions, Trades, Callings and Employments Taxation Act, 1995; Rules, 1996', 'yearly', {'months_after': 4, 'day': 30},
             'return period ends 30 June; the principal officer files Form IV within 30 days after it (r.5(3))', 'Form IV', _P, 'Annual (instalments if authorised)', basis='financial',
             note='Nil up to ₹5,000; ₹75; ₹120; ₹150; ₹180; ₹195; ₹208 above ₹20,000 (2024 schedule).',
             sources=[('Mizoram Taxation — profession tax slab 2024', 'https://zotax.nic.in/files/tax_schedules/Final%20Profession%20Tax%20Slab%202024.pdf')],
             legal_review='The 2024 notification\'s effective date is internally inconsistent.'),
    'MN': pt('Manipur Professions, Trades, Callings and Employments Taxation Act, 1981 (Eighth Amendment, 2012); Rules, 1982', 'yearly', None,
             'deducted in equal monthly instalments and deposited within 30 days; Form V annual return (date not confirmed)', 'Form V', _PART, 'Monthly deduction, annual return', basis='financial',
             note='Annual: nil to ₹50,000; ₹1,200; ₹2,000; ₹2,400; ₹2,500 above ₹1,25,000.',
             sources=[('Manipur PT (Eighth Amendment) Act', 'http://manipurvat.gov.in/document/Manipur%20Prof.Tax%20eight%20amendment%20Act..pdf')]),
    'ML': pt('Meghalaya Professions, Trades, Callings and Employments Taxation Act (Act 15 of 2022)', 'yearly', None,
             'Form IV per financial year (due date under r.10 not read)', 'Form IV', 'CONFLICTING_SOURCES', 'Annual return', basis='financial',
             sources=[('Meghalaya Gazette — Act 15 of 2022', 'https://megpns.gov.in/gazette/2022/09/27-09-22-X.pdf')],
             legal_review='The 2022 Act removed the rate schedule; no rate notification was found.'),
    'PY': pt('Puducherry Municipalities Act, 1973 ss.144–151; Profession Tax Rules, 1976 (professional tax by the local body)', 'half_yearly', {'months_after': 1, 'day': 30},
             'deducted on the Commissioner\'s requisition and credited within 15 days; Form II within 30 days after the half-year', 'Form II', _PART, 'Half-yearly', basis='financial',
             note='Per half-year: nil to ₹99,999; ₹250; ₹500; ₹750; ₹1,000; ₹1,250 above ₹5,00,000.',
             sources=[('Puducherry Local Administration — Profession Tax Rules, 1976', 'https://lad.py.gov.in/puducherry-municipalities-profession-tax-rules-1976'), ('Gazette of Puducherry Ext. Part II No. 25, 11-05-2017 (Act 4 of 2017: maximum half-yearly tax)', 'https://styandptg.py.gov.in/2017/May/EXTRAORDINARYPART-II/1/25-PART-II%20No%2025%20dated%2011-5-2017.pdf'), ('Finance Dept G.O.Ms. No. 24/F3/2017, 04-07-2017', 'https://finance.py.gov.in/sites/default/files/proftaxgo04jul17.pdf')],
             legal_review='The rates are statutory maximums; each council\'s adopted rates are not confirmed.'),
    'OD': pt('Odisha State Tax on Professions, Trades, Callings and Employments Act, 2000 (repealed from 1 Apr 2026 by Ordinance 02 of 2026)', 'monthly', None,
             'deducted monthly; annual Form V return within three months after the year end (for FY 2025-26, by 30 June 2026)', 'Form V (annual)', 'LEGAL_REVIEW_REQUIRED', 'Monthly deduction, annual return',
             note='Repealed from 1 April 2026, with earlier liabilities saved. PeopleOS has no Odisha PT rule version, so the statement is empty and says so.',
             sources=[('Odisha Ordinance 02 of 2026 (Gazette Ext. No. 1496)', 'https://finance.odisha.gov.in/sites/default/files/2026-04/1496-LAW%20DEPTT..pdf'), ('Odisha Commercial Tax — professional tax', 'https://odishatax.gov.in/professional_tax.php?lang=en')],
             legal_review='The repeal is official (Ordinance 02 of 2026). No Repeal Act, assent or Act number was found (official listing read 27-10-2026); without one the Ordinance lapses about 3 November 2026 (calculated) and the 2000 Act revives.', effective_to='2026-03-31'),
    'CG': none('Chhattisgarh Vritti Kar Adhiniyam, 1995', 'The Act exists but every person has been exempt since 1 Apr 2011 (notification F-10/22/2011/CT/V(22)); read from a scan on a non-government host.', _PART),
    'GA': none('Goa Tax on Professions, Trades, Callings and Employments Act, 1985', 'Not among the Acts the State Tax Department administers; local-body levies not checked.', _PART),
    'AR': none('—', 'No levy found (secondary sources only).', _SEC),
}
for code in ('DL', 'HR', 'UP', 'RJ', 'HP', 'UK', 'JK', 'LA', 'CH', 'AN', 'DH', 'LD'):
    PT[code] = none('—', 'No professional tax on salaried employees: no such head in the 2026-27 budget or a department statement' + (' (Rajasthan abolished it from 1 Apr 2004)' if code == 'RJ' else '') + '; local-body levies not checked.', _P)

def lwf(act, employee, employer, frequency, basis, due, due_text, confidence, frequency_text, note='', form='Contribution to the state labour welfare board'):
    return dict(applies=True, act=act, employee=employee, employer=employer, frequency=frequency, basis=basis, due=due, due_text=due_text, form=form,
                confidence=confidence, frequency_text=frequency_text, note=note, notes=[note] if note else [])

HALF = {'months_after': 1, 'day': 15}
P = 'OFFICIAL_PRIMARY_SOURCE_VERIFIED'
PART = 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED'
SEC = 'SECONDARY_SOURCE_ONLY'
REVIEW = 'LEGAL_REVIEW_REQUIRED'
LV = '2026-10-27'
JAN31 = {'months_after': 1, 'day': 'end'}
CEIL = 'PeopleOS does not hold the state\'s managerial and supervisory exclusions (wage ceilings differ by state): check who is excluded before relying on the statement.'

def lwfx(act, employee, employer, frequency, basis, due, due_text, confidence, frequency_text, sources, notes=(), legal_review=None, form='Contribution to the state labour welfare board'):
    d = lwf(act, employee, employer, frequency, basis, due, due_text, confidence, frequency_text, form=form)
    d.update(sources=[list(x) for x in sources], verified_on=LV, notes=[*notes, CEIL], legal_review=legal_review)
    return d

LWF = {
    'MH': lwfx('Maharashtra Labour Welfare Fund Act, 1953 s.6BB(2) as substituted by Mah. Act XXV of 2024; LWF Rules, 1953', '₹25', '₹75 (Government ₹50)', 'half_yearly', 'calendar', HALF,
               'employees on the register on 30 June and 31 December; paid by 15 July and 15 January (due dates from secondary sources)', PART, 'Half-yearly (June, December)',
               [('Maharashtra Labour Welfare Board — contribution rates and Form A-1', 'https://mlwb.in/'), ('Maharashtra Labour Welfare Fund Act, 1953 with Mah. XX of 2023 and XXV of 2024 (PRS reprint)', 'https://prsindia.org/files/bills_acts/acts_states/maharashtra/1953/Act40of1953MH.pdf')],
               notes=['₹25 / ₹75 from 18 Mar 2024 (Mah. Act XXV of 2024), first in the June 2024 deduction; before that ₹6 / ₹18 (wages up to ₹3,000) or ₹12 / ₹36 (Mah. 24 of 2003). The 2024 Act was read only in reprints, not on a government host.'],
               legal_review='The rule pack\'s Maharashtra LWF version is dated from 2015 with the 2024 amounts: periods before June 2024 are overstated (see the rule-change register).', form='Form A-1 (Rule 3-A)'),
    'KA': lwfx('Karnataka Labour Welfare Fund Act, 1965 s.7A(2) as amended by Karnataka Act 05 of 2025', '₹50', '₹100 (Government ₹50)', 'yearly', 'calendar', HALF,
               'employees on the register on 31 December; deducted from December wages; paid online by 15 January', P, 'Annual (calendar year)',
               [('KLWB press note of 05-12-2025 with the gazette copy of Act 05 of 2025', 'https://klwb.karnataka.gov.in/uploads/Contribution%202025%20Press%20Note%20&%20Gazette%20Copy_1765191206.pdf'), ('Karnataka LWF Act, 1965 consolidated to Act 05 of 2026 (Department of Parliamentary Affairs and Legislation)', 'https://dpal.karnataka.gov.in/uploads/media_to_upload1771238983.pdf')],
               notes=['Rate history (s.7A(2) footnotes): ₹6 / ₹12 from 8 Feb 2011; ₹20 / ₹40 from 6 Apr 2017; ₹50 / ₹100 from 10 Jan 2025. December wages only; paid by 15 January, online from 7 Jan 2026.',
                      'Covers factories, motor omnibus services, plantations and workshops, and any other establishment employing ten or more persons from 7 Jan 2026 (Act 05 of 2026; more than fifty before). PeopleOS does not test this threshold.',
                      'The rule pack\'s Karnataka v2 (₹50 / ₹100 from 10 Jan 2025) is prepared for review and not in force; payroll still uses v1 (₹20 / ₹40).']),
    'TN': lwfx('Tamil Nadu Labour Welfare Fund Act, 1972 s.15 (amended 2021); TN LWF Rules, 1973 r.11-A, r.12; G.O.(Ms) 161 of 2 Dec 2022', '₹20', '₹40 (Government ₹20)', 'yearly', 'calendar', JAN31,
               'year ending December, paid by 31 January (Form A); the board\'s copy of r.12 says June wages, paid before 31 July — sources conflict', 'CONFLICTING_SOURCES', 'Annual',
               [('TN Government Gazette Extra. No. 538, 02-12-2022 (SRO A-26(b)/2022: ₹20 / ₹40)', 'https://www.lwb.tn.gov.in/uploads/labo_205/subpage_files/Gazette_538.pdf'), ('TN Government Gazette Extra. No. 21, 23-01-2015 (SRO A-1(a)/2015: ₹10 / ₹20)', 'https://www.stationeryprinting.tn.gov.in/extraordinary/2015/21-Ex-III-1a.pdf'),
                ('Tamil Nadu Labour Welfare Board — G.O.(Ms) 161', 'https://www.lwb.tn.gov.in/uploads/labo_205/subpage_files/GO_161.pdf'), ('Tamil Nadu Labour Welfare Fund Rules, 1973', 'https://www.lwb.tn.gov.in/uploads/labo_205/subpage_files/Tamil_Nadu_Labour_Welfare_Fund_Rules_1973.PDF'), ('Form A', 'https://www.lwb.tn.gov.in/uploads/labo_205/subpage_files/FORM%20A.pdf')],
               notes=['₹20 / ₹40 from 2 Dec 2022; ₹10 / ₹20 from 23 Jan 2015; ₹7 / ₹14 before (official gazettes).'], legal_review='Deduction month and due date conflict: r.12 (June wages, paid before 31 July) against the official Form A and the board\'s practice (year ending December, paid by 31 January). The rule pack\'s Tamil Nadu version is dated from 2015 with the 2022 amounts.', form='Form A (r.12(4))'),
    'TG': lwfx('Andhra Pradesh Labour Welfare Fund Act, 1987 as adapted to Telangana (G.O.Ms. No. 6, 1 Feb 2016); Rules, 1988 r.6', '₹2', '₹5', 'yearly', 'calendar', JAN31,
               'employees on the register on 31 December; paid by 31 January', PART, 'Annual (calendar year)',
               [('Telangana Labour Welfare Fund Act, 1987 (Labour Department)', 'https://labour.telangana.gov.in/content/ActsRules/TELANGANA%20LABOUR%20WELFARE%20FUND%20ACT,%201987.htm'), ('G.O.Ms. No. 6, 01-02-2016', 'https://labour.telangana.gov.in/content/gos/Goms.no.6date.01.02.2016.PDF')],
               notes=['Shops and establishments with 20 or more persons; factories and motor transport undertakings regardless of size.'], form='Form F'),
    'AP': lwfx('Andhra Pradesh Labour Welfare Fund Act, 1987 as amended (Act 7 of 2015; Acts 3 and 27 of 2022); Rules, 1988 r.6', '₹30', '₹70', 'yearly', 'calendar', JAN31,
               'employees on the register on 31 December; paid by 31 January', PART, 'Annual (calendar year)',
               [('AP Labour Department — Labour Welfare Board', 'https://labour.ap.gov.in/'), ('AP Labour Welfare Fund Rules, 1988', 'https://api.apfd.apcfss.in/socialwelfaredms/user-defined-path/file-download/labour/1755842059305_THEANDHRAPRADESHLABOURWELFAREFUNDRULES1988.pdf')],
               legal_review='The amending Act that set ₹30 / ₹70 was not read; its date is not confirmed.', form='Form F'),
    'DL': lwfx('Bombay Labour Welfare Fund Act, 1953 as extended to Delhi; Delhi Labour Welfare Fund Rules, 1997', '₹0.75', '₹2.25 (Government ₹1.50)', 'half_yearly', 'calendar', HALF,
               'employees on the register on 30 June and 31 December; paid before 15 July and 15 January', P, 'Half-yearly (June, December)',
               [('Delhi Labour Welfare Board — notification constituting the fund (13-07-2004)', 'https://dlwb.delhi.gov.in/dlwb/constitution-labour-welfare-fund-national-capital-territory-delhi-notification-ii')],
               notes=['Establishments with five or more persons.']),
    'HR': lwfx('Punjab Labour Welfare Fund Act, 1965 s.9A (Haryana Acts 18 of 2019 and 16 of 2021); HLWB notification HLWB/REV/2026/3436', '0.2% of wages, up to ₹35 a month (from 1 Jan 2026)', 'Twice the employee contribution (up to ₹70)', 'yearly', 'calendar', {'months_after': 0, 'day': 'end'},
               'accrues monthly; paid online once a year, before 31 December (12% interest after a month)', PART, 'Accrues monthly, paid yearly',
               [('Haryana Labour Welfare Board — rates', 'https://hrylabour.gov.in/content/cms/MTU'), ('Punjab Labour Welfare Fund Act, 1965 (Haryana consolidation)', 'https://hrylabour.gov.in/staticdocs/labourActpdfdocs/ThePunjabLabourFundACt1965.pdf')],
               notes=['Caps: ₹25 (2019–2022), ₹31 (2023–2024), ₹34 (2025), ₹35 (from 2026). The rule pack holds these as versions prepared for review; payroll still uses v1.',
                      'Shops and commercial establishments with ten or more employees; factories and industries regardless of size.'],
               legal_review='The 2026 notification was read only from a vendor-hosted scan; whether the ten-employee threshold applies beyond shops is ambiguous.'),
    'GJ': lwfx('Bombay Labour Welfare Fund Act, 1953 s.6-B as amended in Gujarat; Labour Welfare Fund (Gujarat) Rules, 1962', '₹6', '₹12', 'half_yearly', 'calendar', HALF,
               'employees on the register on 30 June and 31 December; paid before 15 July and 15 January (s.6-B(3))', P, 'Half-yearly (June, December)',
               [('Gujarat Government Gazette Extra. No. 634, 15-11-2016 (notification of 10-11-2016: ₹6 / ₹12)', 'https://glwb.gujarat.gov.in/images/glwb/pdf/lwf.pdf'), ('Gujarat Labour Welfare Board — Form A-1', 'https://glwb.gujarat.gov.in/images/glwb/pdf/labour-welfare-fund-form-a-1.pdf'), ('Bombay Labour Welfare Fund Act, 1953 (Gujarat)', 'https://glwb.gujarat.gov.in/images/glwb/pdf/Bombay-labour-welfare-fund-act-1953.pdf')],
               notes=['Shops and establishments with ten or more persons; factories regardless of size.', '₹6 / ₹12 from 15 Nov 2016 (first the December 2016 half-year); ₹3 / ₹6 before.'], form='Form A-1 (Rule 3B)'),
    'WB': lwfx('West Bengal Labour Welfare Fund Act, 1974 (amended 2017 and 2023); notification Labr/576641/2024', '₹3', '₹30', 'half_yearly', 'calendar', HALF,
               'paid before 15 July and 15 January, online', P, 'Half-yearly (June, December)',
               [('Labour Department notification Labr/576641 of 19-11-2024 (₹3 / ₹30 from 1 Jan 2024)', 'https://labour.wb.gov.in/labour_main_api/storage/CMS/Notice/Labr-576641%20Dated-19-11-2024.pdf'), ('West Bengal Labour Welfare Fund Act, 1974 (PRS copy)', 'https://prsindia.org/files/bills_acts/acts_states/west-bengal/1974/ActNo.38of1974WB.pdf')],
               notes=['From 1 Jan 2024; supervisory staff above ₹10,000 a month are excluded from 1 Apr 2024. Commercial establishments with ten or more persons.']),
    'KL': lwfx('Kerala Labour Welfare Fund Act, 1975 (Act 27 of 2021); Kerala Shops and Commercial Establishments Workers Welfare Fund Act, 2006', '₹45 a half-year (LWF Act) or ₹50 a month (shops fund)', '₹45 a half-year or ₹50 a month', 'half_yearly', 'calendar', HALF,
               'LWF Act: by 15 July and 15 January; shops fund: monthly', REVIEW, 'Half-yearly (LWF Act) or monthly (shops fund)',
               [('Kerala Labour Welfare Fund Act, 1975 (PRS copy)', 'https://prsindia.org/files/bills_acts/acts_states/kerala/1977/1977KERALA11.pdf'), ('Kerala Shops and Commercial Establishments Workers Welfare Fund Board — payment', 'https://peedika.kerala.gov.in/Details_Payment.php')],
               legal_review='Two overlapping funds: which one an establishment pays depends on its type and membership.'),
    'MP': lwfx('Madhya Pradesh Shram Kalyan Nidhi Adhiniyam, 1982 s.9; Rules, 1984', '₹10', '₹50 (at least ₹2,500 a half-year below 50 employees)', 'half_yearly', 'calendar', HALF,
               'paid before 15 July and 15 January (s.9(3)), online', PART, 'Half-yearly (June, December)',
               [('MP Shram Kalyan Mandal portal — rate master', 'https://shramkalyanmandal.mp.gov.in/SKM/')],
               notes=['Rates from the January 2026 half-year. PeopleOS has no employer minimum: the ₹2,500 floor is not computed.'], legal_review='The notification of 28-01-2026 was not read; the employer minimum\'s legal wording is not confirmed.'),
    'PB': lwfx('Punjab Labour Welfare Fund Act, 1965 s.9-A (Punjab Acts 18 of 2006 and 32 of 2014)', '₹5 a month', '₹20 a month', 'half_yearly', 'financial', HALF,
               'deducted monthly; paid by 15 October (April–September) and 15 April (October–March)', REVIEW, 'Deducted monthly, paid half-yearly',
               [('Punjab Labour Welfare Fund Act, 1965 (Labour Department)', 'https://pblabour.gov.in/pbLabourStaticSiteAdmin/uploads/5c6efb8f-d1d8-465a-b017-0064a1b1b5ad_the_punjab_labour_welfare_fund_act_1965.pdf'), ('Bill 15-PLA-2025 as passed (₹10 / ₹40)', 'https://punjabassembly.gov.in/images/docs/AssemblyFiles/16/179/20250710/Documents/15%20PLA%20passed%20Bill%202025.pdf')],
               legal_review='A bill raising the amounts to ₹10 / ₹40 passed the Assembly in 2025; whether it was notified is not confirmed.'),
    'CH': lwfx('Punjab Labour Welfare Fund Act, 1965 as applicable to Chandigarh (G.S.R. 353(E), 10 Apr 2018)', '₹5 a month', '₹20 a month', 'half_yearly', 'financial', HALF,
               'deducted monthly; paid by 15 October and 15 April', SEC, 'Deducted monthly, paid half-yearly',
               [('Punjab Labour Welfare Fund Act, 1965 (Punjab Labour Department)', 'https://pblabour.gov.in/pbLabourStaticSiteAdmin/uploads/5c6efb8f-d1d8-465a-b017-0064a1b1b5ad_the_punjab_labour_welfare_fund_act_1965.pdf')],
               legal_review='The extension to Chandigarh rests on a secondary source.'),
    'OD': lwfx('Orissa Labour Welfare Fund Act, 1996 (Orissa Act 14 of 2005); Odisha Labour Welfare Fund Rules, 2015', '₹10 a half-year (₹20 a year)', '₹20 a half-year (₹40 a year)', 'half_yearly', 'calendar', HALF,
               'employees on the register on 30 June and 31 December; paid before 15 July and 15 January', PART, 'Half-yearly (June, December)',
               [('Orissa Labour Welfare Fund Act (PRS copy)', 'https://prsindia.org/files/bills_acts/acts_states/odisha/2005/Odisha%20Act%2014%20of%202005.pdf')],
               notes=['Shops and establishments with 20 or more persons; factories and motor transport undertakings regardless of size.'], legal_review='The half-year split of the yearly amount is an inference.', form='Form F'),
    'CG': lwfx('Chhattisgarh Shram Kalyan Nidhi Adhiniyam, 1982; Rules, 1984', '₹15', '₹45', 'half_yearly', 'calendar', HALF,
               'paid by 15 July and 15 January (not confirmed)', 'UNVERIFIED', 'Half-yearly (June, December)',
               [('Chhattisgarh Labour Department — Labour Welfare Board registration service', 'https://shramevjayate.cg.gov.in/shramik/Lwb/')],
               legal_review='The board exists (official portal), but no official or cited secondary source gives the ₹15 / ₹45 amounts, the months, the due dates or the wage ceiling.'),
    'GA': lwfx('Goa, Daman and Diu Labour Welfare Fund Act, 1986 s.14(1) as substituted by Goa Act 9 of 2015; Goa LWF Rules, 1990 r.4', '₹60 a half-year (₹120 a year)', '₹180 a half-year (₹360 a year; Government ₹240 a year)', 'half_yearly', 'calendar', JAN31,
               'January–June paid by 31 July; July–December by 31 January', P, 'Half-yearly (January–June, July–December)',
               [('Goa Act 9 of 2015, Official Gazette', 'https://labour.goa.gov.in/wp-content/uploads/2020/07/SERIES-I-No.-22.pdf'), ('Goa Labour Welfare Board', 'https://labour.goa.gov.in/labour-welfare-board/')],
               notes=['The ₹10 / ₹30 on the board\'s page is the monthly equivalent of the yearly amounts.'], form='Form A (r.4)'),
    'UP': dict(applies=False, act='Uttar Pradesh Labour Welfare Fund Act, 1965 (amended 2024)', employee='—', employer='—', frequency_text='—', due_text='—', form='—',
               note='The Act exists, but no periodic payroll contribution was found in the sections read (the fund is fed by unpaid wages and fines); the page listing the fund\'s sources was missing from the scan.'),
    'DH': dict(applies=False, act='Goa, Daman and Diu Labour Welfare Fund Act, 1986 (status in Daman and Diu not confirmed)', employee='—', employer='—', frequency_text='—', due_text='—', form='—',
               note='Whether the 1986 Act still applies in Daman and Diu needs legal review.'),
}
for code, url in (('SK', 'https://labour.sikkim.gov.in/AboutUs?menuId=8'), ('PY', 'https://labour.py.gov.in/acts-rules'), ('RJ', 'https://labour.rajasthan.gov.in/ActsAndRules.aspx'), ('ML', 'https://meglc.gov.in/acts_rules.html'), ('UK', 'https://labour.uk.gov.in/document-category/acts-rules/')):
    LWF[code] = dict(applies=False, act='—', employee='—', employer='—', frequency_text='—', due_text='—', form='—', confidence=PART,
                     note='No labour welfare fund Act in the labour department\'s official list of the laws it enforces (' + url + '): evidence of absence for what the list covers.' + (' Uttarakhand administers the U.P. Labour Welfare Fund Rules, 1972, which have no payroll contribution.' if code == 'UK' else ''))
for code in ('AN','AR','AS','BR','DH','HP','JH','JK','LA','LD','MN','ML','MZ','NL','PY','RJ','SK','TR','UK','UP'):
    LWF.setdefault(code, dict(applies=False, act='—', employee='—', employer='—', frequency_text='—', due_text='—', form='—', note='No general labour welfare fund Act found in the PRS state-acts index (evidence of absence, not proof).' + (' Assam has a tea-plantations-only fund.' if code == 'AS' else '')))
