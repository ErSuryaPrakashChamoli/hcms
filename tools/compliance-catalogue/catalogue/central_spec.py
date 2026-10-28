# Central (all-India) obligations, catalogue revision 2026-10-26b.
V = '2026-10-26'
V27 = '2026-10-27'  # the Wages and OSH (Central) Rules re-read on labour.gov.in
# Wages Rules: read on labour.gov.in on 2026-10-27 (the copy's Government of India Press signature reports a digest
# mismatch, the file having been re-saved after signing, as with the SS Rules copy): official host, so primary.
WAGES = ['Code on Wages (Central) Rules, 2026 — G.S.R. 343(E), 8 May 2026, Gazette CG-DL-E-08052026-272365 (MoLE copy)', 'https://www.labour.gov.in/static/uploads/2026/05/6eb0c35ba63b776487a025e5123b6b12.pdf']
WAGES_CONF = 'OFFICIAL_PRIMARY_SOURCE_VERIFIED'
OSH = ['OSH and Working Conditions (Central) Rules, 2026 — G.S.R. 345(E), 8 May 2026, Gazette CG-DL-E-09052026-272379 (MoLE copy with a valid Government of India Press signature)', 'https://www.labour.gov.in/static/uploads/2026/05/ee246f790cad0b8e99c3828f34fa09a6.pdf']
SS = ['Social Security (Central) Rules, 2026 — G.S.R. 344(E), 8 May 2026, Gazette CG-DL-E-08052026-272366 (MoLE copy)', 'https://www.labour.gov.in/static/uploads/2026/05/49aa9b62c2125499c37399b90e969d67.pdf']
IR = ['Industrial Relations (Central) Rules, 2026 — G.S.R. 342(E), 8 May 2026', 'https://egazette.gov.in/WriteReadData/2026/272336.pdf']
EOC = ['Ease of Compliance to Maintain Registers under various Labour Laws Rules, 2017 — G.S.R. 154(E), 21 Feb 2017', 'https://www.simpliance.in/uploads/notifdocs/96/Ease%20Of%20Compliance%20To%20Maintain%20Registers%20Under%20Various%20Labour%20Law%20Rules%2C%202017.pdf']
PIB = ['PIB, 21 Nov 2025 — Labour Codes in force; earlier Acts and rules continue during transition', 'https://www.pib.gov.in/PressReleasePage.aspx?PRID=2192463']
EPF_CEIL = ['MoLE / PIB, 16 Sep 2026 — EPF wage ceiling ₹25,000 from 17 Sep 2026', 'https://www.labour.gov.in/static/uploads/2026/09/4f607a88c5342aeb980c6b999997caab.pdf']
ESIC = ['ESIC — contribution', 'https://www.esic.gov.in/contribution']
F138 = ['Income Tax Department — Form No. 138', 'https://www.incometax.gov.in/iec/foportal/newformpage/forms/form138-um']
# Income-tax Rules, 2026: the gazette copy retrieved from incometaxindia.gov.in on 2026-10-01 (sha256 f565e0f5…, held in
# database/data/compliance/evidence/tds and recorded in the rule pack); r.215, r.218 and r.219 read 2026-10-27.
ITR = ['Income-tax Rules, 2026 — G.S.R. 198(E), 20 Mar 2026 (incometaxindia.gov.in copy retrieved 2026-10-01; r.215, r.218, r.219 read 2026-10-27)', 'https://www.incometaxindia.gov.in/documents/81799/11848482/En-Notified-IT-Rules-2026-20-03-2026.pdf/a332bf2a-da14-8b94-dde2-5a2ea1428318?t=1773990110473']
ESIC_GUIDE = ['ESIC — A Guide for Employers (returns to be submitted online)', 'https://esic.gov.in/attachments/publicationfile/6f673bcd3d6110170f790d2909767b4f.pdf']
SH_ACT = ['Sexual Harassment of Women at Workplace (Prevention, Prohibition and Redressal) Act, 2013 (gazette print, Ministry of Women and Child Development)', 'https://wcd.gov.in/documents/uploaded/1710219823.pdf']
SH_RULES = ['Sexual Harassment of Women at Workplace (Prevention, Prohibition and Redressal) Rules, 2013 — G.S.R. 769(E), 9 Dec 2013 (gazette print, MWCD)', 'https://wcd.gov.in/documents/uploaded/1719914401_2NUqe91gql.pdf']
STATE_RULES = 'State rules under the Code on Wages, 2019 where the state has notified them; otherwise the earlier Acts and the state\'s rules continue (PIB, 21 Nov 2025)'
CFG = 'Set the establishment\'s appropriate government (Central or State Government): it decides which form applies.'

def register_variants(central_form, central_basis, central_src, legacy_form, legacy_basis, legacy_src, state_label):
    return [
        {'sphere': 'central', 'from': '2026-05-08', 'form': central_form, 'legal_basis': central_basis, 'confidence': WAGES_CONF if central_src is WAGES else 'OFFICIAL_PRIMARY_SOURCE_VERIFIED', 'sources': [central_src], 'verified_on': V27 if central_src in (WAGES, OSH) else V},
        {'sphere': 'central', 'to': '2026-05-07', 'form': legacy_form, 'legal_basis': legacy_basis, 'confidence': 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED', 'sources': legacy_src, 'verified_on': V,
         'legal_review': 'Periods before the central rules (8 May 2026); confirm the treatment of May 2026, which straddles them.'},
        {'sphere': 'state', 'form': f'{state_label} — PeopleOS layout with the particulars of {central_form}; keep it in the form the state prescribes', 'legal_basis': STATE_RULES,
         'confidence': 'LEGAL_REVIEW_REQUIRED', 'sources': [PIB], 'verified_on': V, 'legal_review': 'The state form depends on whether the state has notified final rules under the Codes (see the state matrix).'},
        {'sphere': 'unknown', 'form': f'{central_form} (Central Government) or the state\'s form (State Government)', 'legal_basis': central_basis + '; or ' + STATE_RULES,
         'confidence': 'LEGAL_REVIEW_REQUIRED', 'sources': [central_src, PIB], 'verified_on': V, 'configuration': CFG},
    ]

C = {}
C['employee_register'] = dict(title='Employee register', category='register', frequency='register', scope='establishment', builder='employee_register', source='people', output='register',
    form='Form I (= OSH Form XIII)', legal_basis='Code on Wages, 2019 s.50(1); Wages (Central) Rules, 2026 r.51(1)(i)', due_text='Kept current, electronically or on paper; preserved five years after the last entry',
    confidence=WAGES_CONF, sources=[WAGES], verified_on=V27,
    variants=register_variants('Form I (= OSH Form XIII)', 'Code on Wages, 2019 s.50(1); Wages (Central) Rules, 2026 r.51(1)(i)', WAGES, 'Form A (employee register)', 'Ease of Compliance to Maintain Registers Rules, 2017 (Form A)', [EOC], 'Register of employees'))
C['wage_register'] = dict(title='Register of wages, overtime, advances, fines and deductions', category='register', frequency='monthly', scope='establishment', builder='wage_register', output='register',
    form='Form IV (= OSH Form XV)', legal_basis='Code on Wages, 2019 s.17–21 and s.50; Wages (Central) Rules, 2026 r.51(1)(ii), (2), (3)', due={'months_after': 1, 'day': 7}, due_text='monthly wages paid before the 7th of the next month',
    confidence=WAGES_CONF, sources=[WAGES], verified_on=V27,
    variants=register_variants('Form IV (= OSH Form XV)', 'Code on Wages, 2019 s.17–21 and s.50; Wages (Central) Rules, 2026 r.51(1)(ii), (2), (3)', WAGES, 'Form B (wage register) and Form C (loans and recoveries)', 'Ease of Compliance to Maintain Registers Rules, 2017 (Forms B and C)', [EOC], 'Register of wages'))
C['muster_roll'] = dict(title='Attendance register-cum-muster roll', category='register', frequency='monthly', scope='establishment', builder='muster_roll', source='attendance', output='register',
    form='Form IX (= OSH Form XIV)', legal_basis='Code on Wages, 2019 s.50(1); Wages (Central) Rules, 2026 r.51(1)(iii)', due_text='Kept daily; preserved five years after the last entry',
    confidence=WAGES_CONF, sources=[WAGES], verified_on=V27,
    variants=register_variants('Form IX (= OSH Form XIV)', 'Code on Wages, 2019 s.50(1); Wages (Central) Rules, 2026 r.51(1)(iii)', WAGES, 'Form D (attendance register)', 'Ease of Compliance to Maintain Registers Rules, 2017 (Form D)', [EOC], 'Muster roll'))
C['deduction_register'] = dict(title='Deductions, loans, advances and fines — detail', category='register', frequency='monthly', scope='establishment', builder='deduction_register', output='register',
    form='Supports Form IV (earlier Form C)', legal_basis='Code on Wages, 2019 s.18–21; Wages (Central) Rules, 2026 r.51(2)–(3)', due_text='Kept current',
    confidence=WAGES_CONF, sources=[WAGES], verified_on=V27, notes=['Detail behind the deduction columns of the wage register.'],
    legal_review='Form IV / Form V under the Wages (Central) Rules apply where the Central Government is the appropriate government; for state-sphere establishments the state\'s rules decide the form.')
C['overtime_register'] = dict(title='Overtime detail', category='register', frequency='monthly', scope='establishment', builder='overtime_register', source='attendance', output='register',
    form='Supports Forms IV and IX (overtime hours and wages)', legal_basis='Code on Wages, 2019 s.14 (overtime at twice the rate of wages); OSH Code, 2020 s.27', due_text='Kept current',
    confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED', sources=[WAGES], verified_on=V27, notes=['Factories keep the overtime register their state factories rules prescribe.'])
C['leave_register'] = dict(title='Register for leave with wages', category='register', frequency='register', scope='establishment', builder='leave_register', source='leave', output='register',
    form='Form XX', legal_basis='OSH Code, 2020 s.32; OSH (Central) Rules, 2026 r.76', due_text='Kept current; shared with the employee once a calendar year on demand',
    confidence='OFFICIAL_PRIMARY_SOURCE_VERIFIED', sources=[OSH], verified_on=V27,
    variants=register_variants('Form XX', 'OSH Code, 2020 s.32; OSH (Central) Rules, 2026 r.76', OSH, 'Form E (register of rest, leave and leave wages)', 'Ease of Compliance to Maintain Registers Rules, 2017 (Form E)', [EOC], 'Leave register'))
C['maternity_register'] = dict(code='ss', title='Register of women employees (maternity benefit)', category='register', frequency='register', scope='establishment', builder='maternity_register', source='leave', output='register',
    options={'leave_codes': ['ML']}, form='Form XXII', legal_basis='Code on Social Security, 2020 Chapter VI; SS (Central) Rules, 2026 (register of women employees)', due_text='Kept current',
    confidence='OFFICIAL_PRIMARY_SOURCE_VERIFIED', sources=[SS], verified_on=V,
    variants=[
        {'sphere': 'central', 'from': '2026-05-08', 'form': 'Form XXII', 'legal_basis': 'Code on Social Security, 2020 Chapter VI; SS (Central) Rules, 2026 (register of women employees)', 'confidence': 'OFFICIAL_PRIMARY_SOURCE_VERIFIED', 'sources': [SS], 'verified_on': V},
        {'sphere': 'central', 'to': '2026-05-07', 'form': 'Register under the Maternity Benefit Act, 1961 and its central rules', 'legal_basis': 'Maternity Benefit Act, 1961 s.20; Maternity Benefit (Mines and Circus) Rules, 1963', 'confidence': 'LEGAL_REVIEW_REQUIRED', 'sources': [PIB], 'verified_on': V, 'legal_review': 'Form under the earlier rules not verified.'},
        {'sphere': 'state', 'form': 'Register of women employees — PeopleOS layout with the particulars of Form XXII; keep it in the form the state\'s maternity benefit rules prescribe', 'legal_basis': 'State rules under the Code on Social Security where notified; otherwise the state\'s Maternity Benefit Rules', 'confidence': 'LEGAL_REVIEW_REQUIRED', 'sources': [PIB], 'verified_on': V, 'legal_review': 'State form not verified.'},
        {'sphere': 'unknown', 'form': 'Form XXII (Central Government) or the state\'s form', 'legal_basis': 'Code on Social Security, 2020 Chapter VI', 'confidence': 'LEGAL_REVIEW_REQUIRED', 'sources': [SS], 'verified_on': V, 'configuration': CFG},
    ])
# Challans
C['pf_challan'] = dict(title='PF statement and challan (ECR working)', category='challan', frequency='monthly', scope='establishment', builder='pf_challan', requires='EPF', registration='epf_establishment_code',
    output='statement', filing='EPFO unified portal (ECR upload and challan payment)', form='ECR — Electronic Challan cum Return (A/c 1, 2, 10, 21, 22)',
    legal_basis='Code on Social Security, 2020 Chapter III; EPF Scheme, 1952 para 38, EPS 1995, EDLI 1976 (continuing)', due={'months_after': 1, 'day': 15}, due_text='ECR filed and challan paid by the 15th of the next month',
    confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED', sources=[EPF_CEIL], verified_on=V,
    legal_review='EPF wage ceiling ₹25,000 from 17 Sep 2026: the September 2026 treatment is not established (open regulatory notice).')
C['esi_challan'] = dict(title='ESI contribution statement and challan', category='challan', frequency='monthly', scope='establishment', builder='esi_challan', requires='ESI', registration='esic_employer_code',
    output='statement', filing='ESIC portal (monthly contribution and challan)', form='Monthly contribution (ESIC portal)',
    legal_basis='Code on Social Security, 2020 Chapter IV; ESI (General) Regulations, 1950 (continuing)', due={'months_after': 1, 'day': 15}, due_text='paid within 15 days of the month end',
    confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED', sources=[ESIC], verified_on=V)
C['tds_deposit'] = dict(title='TDS on salary — monthly deduction and deposit', category='challan', frequency='monthly', scope='legal_entity', builder='tds_statement', registration='tan',
    output='statement', filing='Income-tax e-payment (TAN challan)', form='Tax deposited through the income-tax challan (TAN)',
    legal_basis='Income-tax Act, 2025 s.392; Income-tax Rules, 2026 r.218', due={'months_after': 1, 'day': 7, 'end_month': {3: {'months_after': 1, 'day': 30}}}, due_text='by the 7th of the next month (March: 30 April)',
    confidence='OFFICIAL_PRIMARY_SOURCE_VERIFIED', sources=[ITR], verified_on='2026-10-27',
    notes=['r.218(2): seven days from the end of the month; tax deducted in March by 30 April. r.218(4): quarterly payment only with the Assessing Officer\'s approval.'])
C['esi_contribution_period'] = dict(title='ESI contribution period summary', category='challan', frequency='half_yearly', basis='financial', scope='establishment', builder='esi_contribution_period', requires='ESI',
    registration='esic_employer_code', output='check', obligation='internal_control', form='Contribution periods April–September and October–March',
    legal_basis='ESI (General) Regulations, 1950 (contribution and benefit periods)', due_text='Internal working paper', confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED', sources=[ESIC_GUIDE, ESIC], verified_on='2026-10-27',
    notes=['ESIC\'s employer guide: the half-yearly return of contributions (by 11 November and 12 May) "is generated online on the basis of the Monthly contribution filed" — no separate filing. The regulation itself was not read.'],
    legal_review='Whether the ESI regulations continue under the Code on Social Security (s.164(2)(b) is not in force); whether the Form 01-A annual return is still required.')
# Returns
C['tds_quarterly'] = dict(title='TDS on salary — quarterly statement working', category='return', frequency='quarterly', basis='financial', scope='legal_entity', builder='tds_statement', registration='tan',
    output='working_sheet', filing='Income-tax / TRACES (Form No. 138 statement)', form='Form No. 138 (earlier Form 24Q)', legal_basis='Income-tax Act, 2025 s.397(3)(b); Income-tax Rules, 2026 r.219',
    due={'months_after': 1, 'day': 31, 'end_month': {3: {'months_after': 2, 'day': 31}}}, due_text='31 July, 31 October, 31 January and 31 May', effective_from='2026-04-01',
    confidence='OFFICIAL_PRIMARY_SOURCE_VERIFIED', sources=[ITR, F138], verified_on='2026-10-27', notes=['r.219(4): 31 July, 31 October, 31 January, and 31 May after the tax year.', 'Generate, approve and export the statement under Compliance → TDS returns.'])
C['tds_quarterly_24q'] = dict(title='TDS on salary — quarterly statement working (Form 24Q)', category='return', frequency='quarterly', basis='financial', scope='legal_entity', builder='tds_statement', registration='tan',
    output='working_sheet', filing='TRACES (Form 24Q)', form='Form 24Q', legal_basis='Income-tax Act, 1961 s.192 and s.200(3); Income-tax Rules, 1962 r.31A',
    due={'months_after': 1, 'day': 31, 'end_month': {3: {'months_after': 2, 'day': 31}}}, due_text='31 July, 31 October, 31 January and 31 May', effective_to='2026-03-31',
    confidence='UNVERIFIED', sources=[], verified_on=V, legal_review='Tax years before the Income-tax Act, 2025 (to 31 March 2026); the 1962 Rules were not read.')
C['half_yearly_return'] = dict(title='Half-yearly return working sheet (factories)', category='return', frequency='half_yearly', basis='calendar', scope='establishment', builder='return_working_sheet', source='attendance',
    output='working_sheet', filing='State factories / labour portal', establishment_types=['factory'], form='Half-yearly return under the state factories rules',
    legal_basis='State factories rules, until the state\'s rules under the OSH Code apply', due={'months_after': 1, 'day': 31}, due_text='by 31 July and 31 January (state forms vary)',
    confidence='UNVERIFIED', sources=[], verified_on=V, legal_review='State form and due date not verified.')
# Payroll checks
C['salary_variance'] = dict(title='Salary variance report', category='payroll', frequency='monthly', scope='company', builder='salary_variance', output='check', obligation='internal_control',
    form='PeopleOS payroll check', legal_basis='Internal control (not a statutory obligation): month-on-month payroll review', due_text='Before payroll is approved',
    sources=[], verified_on=V, notes=['Reads the newest calculated run of each month, finalized or not, and says so: it is the one report on unfinalized payroll.'])
C['joiners_leavers'] = dict(title='Joiners and leavers — statutory actions', category='payroll', frequency='monthly', scope='establishment', builder='joiners_leavers', source='people', output='check', obligation='internal_control',
    filing='EPFO and ESIC portals (registration of joiners; date of exit of leavers)', form='UAN / ESIC registration of joiners; date of exit of leavers',
    legal_basis='Code on Social Security, 2020 (EPFO and ESIC membership); Code on Wages, 2019 s.17(2) (wages within two working days of separation)', due={'months_after': 1, 'day': 15}, due_text='before the month\'s ECR and ESI contribution',
    confidence='UNVERIFIED', sources=[], verified_on=V)
# Annual
C['annual_return'] = dict(title='Unified annual return working sheet', category='annual', frequency='yearly', basis='calendar', scope='establishment', builder='return_working_sheet', source='attendance',
    options={'part_iv': True}, output='working_sheet', filing='Shram Suvidha / designated portal', sphere=None,
    form='Form XVII (OSH (Central) Rules, 2026) — Parts I and IV', legal_basis='OSH Code, 2020; OSH (Central) Rules, 2026 r.72(5) and r.74; Wages (Central) Rules, 2026 r.48',
    due={'months_after': 2, 'day': 'end'}, due_text='uploaded by 28/29 February for the previous calendar year', confidence='OFFICIAL_PRIMARY_SOURCE_VERIFIED', sources=[OSH], verified_on=V27,
    variants=[
        {'sphere': 'central', 'from': '2026-05-08', 'form': 'Form XVII (OSH (Central) Rules, 2026) — Parts I and IV', 'legal_basis': 'OSH (Central) Rules, 2026 r.72(5) and r.74', 'confidence': 'OFFICIAL_PRIMARY_SOURCE_VERIFIED', 'sources': [OSH], 'verified_on': V},
        {'sphere': 'central', 'to': '2026-05-07', 'form': 'Annual returns under the earlier central Acts (unified annual return on Shram Suvidha)', 'legal_basis': 'Earlier central Acts and rules (transition, PIB 21 Nov 2025)', 'confidence': 'LEGAL_REVIEW_REQUIRED', 'sources': [PIB], 'verified_on': V, 'legal_review': 'Calendar years before the central rules.'},
        {'sphere': 'state', 'form': 'Annual return under the state\'s rules (Code rules where notified, otherwise the earlier Acts and the state\'s combined return) — figures from this working sheet', 'legal_basis': STATE_RULES, 'confidence': 'LEGAL_REVIEW_REQUIRED', 'sources': [PIB], 'verified_on': V, 'legal_review': 'State form and due date depend on the state (see the state matrix).'},
        {'sphere': 'unknown', 'form': 'Form XVII (Central Government) or the state\'s annual return', 'legal_basis': 'OSH (Central) Rules, 2026 r.72(5); or the state\'s rules', 'confidence': 'LEGAL_REVIEW_REQUIRED', 'sources': [OSH], 'verified_on': V, 'configuration': CFG},
    ])
C['employment_return'] = dict(code='ss', title='Employment Information Return working', category='annual', frequency='yearly', basis='financial', scope='establishment', builder='employment_return', min_employees=20, threshold_measure='employed_period_end',
    threshold_basis='SS (Central) Rules, 2026 r.56, Explanation (B): private establishment = 20 or more employees', threshold_confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED',
    output='working_sheet', filing='Career Centre / National Career Service portal, as the state specifies', effective_from='2026-05-08',
    form='Form XXVI (replaces ER-I and ER-II)', legal_basis='Code on Social Security, 2020 Chapter XIII; SS (Central) Rules, 2026 r.56(6)', due={'months_after': 1, 'day': 30}, due_text='within 30 days after 31 March (by 30 April)',
    confidence='OFFICIAL_PRIMARY_SOURCE_VERIFIED', sources=[SS], verified_on=V,
    legal_review='For private establishments the duty starts from the date the appropriate government notifies (r.56(1)(b)); the yearly return is filed as the state specifies.')
C['employment_return_er1'] = dict(title='Quarterly employment return (ER-I) working', category='return', frequency='quarterly', basis='calendar', scope='establishment', builder='employment_return', min_employees=25, threshold_measure='employed_period_end',
    threshold_basis='Employment Exchanges (CNV) Act, 1959 s.1(3): private establishments with 25 or more employees', threshold_confidence='SECONDARY_SOURCE_ONLY',
    output='working_sheet', filing='National Career Service portal', effective_to='2026-05-07', form='Form ER-I',
    legal_basis='Employment Exchanges (Compulsory Notification of Vacancies) Act, 1959 s.5 and Rules, 1960 r.6', due={'months_after': 1, 'day': 30}, due_text='within 30 days of the quarter end',
    confidence='LEGAL_REVIEW_REQUIRED', sources=[SS, PIB], verified_on=V,
    legal_review='The CNV Rules, 1960 were superseded by the SS (Central) Rules on 8 May 2026; whether ER-I was still due for quarters after the Code\'s commencement (21 Nov 2025) depends on the transition — confirm.')
C['bonus_register'] = dict(title='Bonus register', category='annual', frequency='yearly', basis='financial', scope='establishment', builder='bonus_register', min_employees=20, threshold_measure='employed_any_day',
    threshold_basis='Code on Wages, 2019 s.41(2): establishments where 20 or more persons are employed on any day of the accounting year', threshold_confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED',
    options={'payment_within_months': 8}, output='register', filing='Unified annual return, Part I section H (number covered, bonus paid, date)',
    form='No form prescribed under the Code (internal register on the lines of the earlier Form C)', legal_basis='Code on Wages, 2019 Chapter IV s.26–41; Wages (Central) Rules, 2026',
    due={'months_after': 8, 'day': 'end'}, due_text='credited within 8 months of the close of the accounting year (30 November)', confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED', sources=[WAGES], verified_on=V27,
    legal_review='Bonus ceilings come from the BONUS rule version (prepared for review, not in force); whether central notifications bind state-sphere establishments needs review.')
C['gratuity_register'] = dict(code='ss', title='Gratuity eligibility and liability', category='annual', frequency='yearly', basis='financial', scope='establishment', builder='gratuity_register', min_employees=10, threshold_measure='gratuity_coverage',
    threshold_basis='Code on Social Security, 2020 First Schedule, Chapter V: every factory, mine, oilfield, plantation, port and railway company, and every shop or establishment in which ten or more employees are or were employed on any day of the preceding twelve months; s.1(8): coverage continues when the number later falls (India Code text, as on 21 Nov 2025; checked 2026-10-27)', threshold_confidence='OFFICIAL_PRIMARY_SOURCE_VERIFIED',
    output='register', obligation='statutory', form='No register prescribed; nominations in Form III, applications Form IV, employer notice Form V',
    legal_basis='Code on Social Security, 2020 Chapter V s.53–58; SS (Central) Rules, 2026 r.32–33', due_text='Paid by the 30th day after the application', confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED', sources=[SS, ['Code on Social Security, 2020 (India Code, as on 21 Nov 2025): s.1(8), First Schedule', 'https://www.indiacode.nic.in/bitstream/123456789/16823/1/aA2020-36.pdf']], verified_on='2026-10-27',
    notes=['A liability and eligibility working register; the statute prescribes no register.', 'Coverage is decided as the First Schedule decides it (GratuityEntitlements::coverage): factories, mines and plantations whatever their size; others from ten employees on their busiest day, and once covered, always. Contract labour and others PeopleOS does not hold are not counted.'])
C['equal_remuneration'] = dict(title='Equal remuneration check', category='annual', frequency='yearly', basis='financial', scope='establishment', builder='equal_remuneration', output='check', obligation='internal_control',
    form='No form prescribed under the Code (earlier Form D of the Equal Remuneration Rules, 1976)', legal_basis='Code on Wages, 2019 s.3 (no discrimination on the ground of gender)', due_text='Review yearly',
    confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED', sources=[WAGES], verified_on=V27)
C['holiday_list'] = dict(title='List of national, festival and paid holidays', category='annual', frequency='yearly', basis='calendar', scope='establishment', builder='holiday_list', source='attendance', output='register',
    form='Notice of holidays (state national and festival holidays Acts)', legal_basis='State industrial establishments (national and festival holidays) Acts; shops and establishments Acts', due_text='Displayed before the year begins',
    confidence='UNVERIFIED', sources=[OSH], verified_on='2026-10-27', legal_review='State notice form and timing not verified. No central form exists: the OSH (Central) Rules, 2026 prescribe only weekly and compensatory holiday notices (r.67–68). State national and festival holidays Acts (e.g. Karnataka 1963, Tamil Nadu 1958, Kerala 1958, Andhra Pradesh 1974) were identified but not read, nor whether they survive the OSH Code.')
C['tds_annual'] = dict(title='Salary TDS — annual summary and certificates', category='annual', frequency='yearly', basis='financial', scope='legal_entity', builder='tds_statement', options={'certificates': True}, registration='tan',
    output='working_sheet', filing='Certificates issued to employees (Form No. 130)', form='Form No. 130 (earlier Form 16)', legal_basis='Income-tax Act, 2025 s.392 and the Income-tax Rules, 2026',
    due={'months_after': 3, 'day': 15}, due_text='certificates issued by 15 June', confidence='OFFICIAL_PRIMARY_SOURCE_VERIFIED', sources=[ITR], verified_on='2026-10-27',
    notes=['r.215: Form No. 130 by 15 June after the tax year; with more than one employer each issues Parts A and B.', 'Certificates are generated and issued under Compliance → TDS certificates.'],
    legal_review='Whether certificates for tax year 2025-26 (due 15 June 2026) were Form 16 under the 1962 Rules.')
C['posh_annual_report'] = dict(title='Internal Committee annual report (PoSH)', category='annual', frequency='yearly', basis='calendar', scope='company', builder='posh_annual_report', source='grievance',
    options={'category_codes': ['POSH']}, output='working_sheet', filing='Internal Committee to the employer and the District Officer', form='Annual report (Rule 14)',
    legal_basis='Sexual Harassment of Women at Workplace (Prevention, Prohibition and Redressal) Act, 2013 s.21–22 and Rules, 2013 r.14',
    due_text='For each calendar year, to the employer and the District Officer; neither the Act nor the central Rules set a date', confidence='OFFICIAL_PRIMARY_SOURCE_VERIFIED', sources=[SH_ACT, SH_RULES], verified_on='2026-10-27',
    notes=['s.22: the employer also includes the number of cases and their disposal in its own annual report, or intimates them to the District Officer.'],
    legal_review='Any deadline is set by the state or the District Officer: not researched.')
# Checklist
def chk(**kw):
    return dict(category='checklist', output='checklist', verified_on=V, **kw)
C['osh_registration_update'] = chk(title='Update the establishment\'s registration on Shram Suvidha', frequency='event', legal_basis='OSH (Central) Rules, 2026 r.3(6) and (8)', form='Form I (OSH)',
    due_text='Within six months of 8 May 2026 (about 8 November 2026); later changes within 30 days', confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED', sources=[OSH], notes=['Applies to establishments already registered under a central labour law.'])
C['wage_slips'] = chk(title='Wage slips to every employee', frequency='monthly', legal_basis='Code on Wages, 2019 s.50(3); Wages (Central) Rules, 2026 r.52', form='Form V (= OSH Form XVI)',
    due={'months_after': 1, 'day': 7}, due_text='on or before payment of wages', notes=['Issued from Payroll → Payslips.'], confidence=WAGES_CONF, sources=[WAGES],
    legal_review='Form IV / Form V under the Wages (Central) Rules apply where the Central Government is the appropriate government; for state-sphere establishments the state\'s rules decide the form.')
C['appointment_letters'] = chk(title='Letter of appointment to every employee', frequency='event', legal_basis='OSH Code, 2020 s.6(1)(f); OSH (Central) Rules, 2026 r.6', form='Appointment letter (prescribed particulars)',
    due_text='Before employment', notes=['Issued from Lifecycle → Letters.'], confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED', sources=[OSH])
C['employee_tax_declarations'] = chk(title='Employees\' claims for salary TDS', frequency='yearly', basis='financial', legal_basis='Income-tax Act, 2025 s.392(5)(b); Income-tax Rules, 2026 r.205', form='Form No. 124 (earlier Form 12BB)',
    due_text='Collected each tax year before the final TDS', notes=['Collected under Payroll → Tax declarations.'], confidence='UNVERIFIED', sources=[])
C['health_checkup'] = chk(title='Annual health check-up of employees above the prescribed age', frequency='yearly', legal_basis='OSH Code, 2020 s.6(1)(c)', form='Health check-up record',
    due_text='Every year, free of cost, as the rules prescribe', confidence='UNVERIFIED', sources=[])
C['accident_register'] = chk(title='Register of accidents and dangerous occurrences; accident notices', frequency='event', legal_basis='OSH Code, 2020 s.10, 11 and 33; OSH (Central) Rules, 2026 r.7 and r.75', form='Form XIX (register); Form XI (notice)',
    due_text='Death forthwith; injuries preventing work for 48 hours or more within 12 hours after the 48 hours; dangerous occurrences within 12 hours', confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED', sources=[OSH])
C['contract_labour'] = chk(title='Contract labour: principal employer return and contractors\' half-yearly returns', frequency='half_yearly', basis='calendar', legal_basis='OSH Code, 2020 Chapter XI; OSH (Central) Rules, 2026 r.98(7) and (9)',
    form='Form XVIII (contractor, half-yearly); Form XVII Part III (principal employer, yearly)', due={'months_after': 1, 'day': 30}, due_text='contractor returns by 30 July and 30 January; principal employer by the end of February',
    notes=['PeopleOS does not hold contract labour.'], confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED', sources=[OSH])
C['vacancy_reporting'] = chk(title='Vacancy reporting to the Career Centre', frequency='event', legal_basis='Code on Social Security, 2020 Chapter XIII; SS (Central) Rules, 2026 r.56(3)–(4)', form='Form XXV',
    due_text='15 days (regional) or 40 days (central) before the last date for applications, from the date the appropriate government notifies', confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED', sources=[SS])
C['gratuity_nominations'] = chk(title='Gratuity nominations', frequency='event', legal_basis='Code on Social Security, 2020 s.55; SS (Central) Rules, 2026 r.32', form='Form III',
    due_text='Within 90 days of completing one year of service; attested copy returned within 30 days', confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED', sources=[SS])
C['maternity_forms'] = chk(title='Maternity claims and the abstract of maternity provisions', frequency='event', legal_basis='Code on Social Security, 2020 s.60–65 and s.71; SS (Central) Rules, 2026', form='Forms X and XI (claims); Form XIV (abstract, displayed)',
    due_text='Benefit in advance on proof of pregnancy; the balance within 48 hours of proof of delivery', confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED', sources=[SS])
C['retrenchment'] = chk(title='Retrenchment and closure notices; worker re-skilling fund', frequency='event', legal_basis='Industrial Relations Code, 2020 s.70 and Chapter X; IR (Central) Rules, 2026 r.27, r.28 and r.37',
    form='Form XIII (notice); Form XIV (permission, 300+ workers)', due_text='Notice within 3 days; fund transfer of 15 days\' wages per worker within 10 days of retrenchment', confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED', sources=[IR])
C['notices_display'] = chk(title='Statutory notices and abstracts displayed', frequency='register', legal_basis='Code on Wages, 2019 s.50; OSH Code, 2020; Code on Social Security, 2020 s.71; state shops and establishments Acts',
    form='Abstracts, wage period and date of payment, holidays, Internal Committee details', due_text='Displayed at the workplace', confidence='UNVERIFIED', sources=[])
C['committees'] = chk(title='Grievance redressal committee, works committee and Internal Committee', frequency='register', legal_basis='Industrial Relations Code, 2020 s.3 (works committee, 100+ workers) and s.4 (grievance redressal committee, 20+ workers); PoSH Act, 2013 s.4 (10+ employees)',
    form='Constitution and minutes', due_text='Constituted and kept current', confidence='OFFICIAL_SOURCE_PARTIALLY_VERIFIED', sources=[IR])
C['standing_orders'] = chk(title='Standing orders', frequency='event', legal_basis='Industrial Relations Code, 2020 s.28–30 (establishments with 300+ workers)', form='Certified or model standing orders',
    due_text='Adopted and kept current', confidence='UNVERIFIED', sources=[])
C['apprentices'] = chk(title='Apprenticeship returns', frequency='half_yearly', basis='calendar', legal_basis='Apprentices Act, 1961; Apprenticeship Rules, 1992 r.14', form='Apprenticeship portal returns',
    due={'months_after': 1, 'day': 15}, due_text='by 15 July and 15 January, as the apprenticeship portal requires (not confirmed)', confidence='UNVERIFIED', sources=[])


# State rules under the Codes (verify-state-code-rules.md, 2026-10-27). Only states with FINAL rules get their own
# variant, from the rules' effective date; elsewhere, and before that date, the generic state variant applies (the
# earlier Acts' rules continue). A gazette print on a non-government host counts as partly verified.
SV = '2026-10-27'
def _src(title, url):
    return [title, url]
def _conf(url):
    import re
    return 'OFFICIAL_PRIMARY_SOURCE_VERIFIED' if re.search(r'\.(gov|nic)\.in/', url) else 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED'

WAGES_RULES = {
    'AP': ('2026-06-29', 'Andhra Pradesh Code on Wages Rules, 2026 (G.O.Rt. No. 124, 29-06-2026)', 'https://www.sgcms.com/regulatory-updates/wp-content/uploads/2026/08/AP-Code-on-Wages-1.pdf', 'Form I', 'Form IV', 'Form IX', None),
    'BR': ('2026-07-01', 'Code on Wages (Bihar) Rules, 2026 (Bihar Gazette Extra, 01-07-2026)', 'https://www.simpliance.in/India/LEI/govt_notification/Bihar/notification-of-the-code-on-wagesbiharrules-2026-9806', 'Form I', 'Form IV', 'Form IX', 'The rules supersede the Bihar Payment of Wages and Minimum Wages Rules without a savings clause.'),
    'LA': ('2026-08-03', 'Code on Wages (Ladakh) Rules, 2026 (S.O. 231, gazette 03-08-2026)', 'https://egazette.ladakh.gov.in/WriteReadData/2026/1787.pdf', 'Form I', 'Form IV', 'Form IX', None),
    'GJ': ('2025-11-21', 'Gujarat Code on Wages Rules, 2021 (notification KHR/2021/128, 05-10-2021; in force with the Code on 21-11-2025)', 'https://col.gujarat.gov.in/Portal/Document/3_3122_1_Gujaratrules_1_24062026.pdf', 'Form IV', 'Form I (wages, overtime, fines and deductions)', 'Form 14 (Gujarat OSH Rules, 2025): the wages rules prescribe no muster roll', 'Read on col.gujarat.gov.in (r.42(3): Forms I and IV; r.43: wage slip Form V within five working days of payment); the gazette print and its date are not confirmed, and Form 14 of the OSH Rules was read only in a reprint. The rules predate the central rules of 8 May 2026 and were not re-aligned.'),
    'AR': ('2025-11-21', 'Arunachal Pradesh Code on Wages Rules, 2022 (gazette 24-02-2022, in force with the Code on 21-11-2025)', 'https://employmentlaw.lkslaw.com/file/updates/state-rules/arunachal-pradesh/arunachal-wages-rules-2022.pdf', 'Form IV', 'Form I (combined wages register)', 'No muster roll prescribed (the OSH Rules\' Form VIII combined register)', 'The rules predate the central rules and were not re-aligned.'),
    'SK': ('2025-11-21', 'Sikkim Code on Wages Rules, 2022 (Gazette Extra No. 168, 28-04-2022)', 'https://www.sikkim.gov.in/uploads/Gazette/168_20240718.pdf', 'Form IV', 'Form I (wages, overtime, fines and deductions, with days worked)', 'No muster roll prescribed: days worked are recorded in Form I', 'The rules were published in 2022, before the Code commenced; their effective date needs legal review.'),
    'LD': ('2026-06-15', 'Code on Wages (Lakshadweep) Rules, 2025 (Gazette Extra Vol. LXII No. 20, 15-06-2026)', 'https://cdn.s3waas.gov.in/s358238e9ae2dd305d79c2ebc8c1883422/uploads/2026/06/17818725169485.pdf', 'Form IV', 'Form I', 'Form-14 (Lakshadweep OSH Rules, 2026)', None),
    'RJ': ('2026-08-12', 'Rajasthan Code on Wages Rules, 2026 (S.O. 40, 12-08-2026)', 'https://labour.rajasthan.gov.in/notification.aspx', 'Form IV', 'Form I', 'Form V (muster roll and details of overtime)', None),
    'UP': ('2026-08-12', 'Uttar Pradesh Code on Wages Rules, 2026 (No. 1377/XXXVI-03-2026, 12-08-2026)', 'https://simpliance.in/India/LEI/govt_notification/uttar-pradesh/final-notification-of-the-uttar-pradesh-code-on-wages-rules-2026-10193', 'Form II', 'Form I', 'Form XIII (appended to the rules but not cited by any rule)', None),
    'UK': ('2026-08-25', 'Uttarakhand Code on Wages Rules, 2026 (Gazette Extra Kram 55, 25-08-2026)', 'https://cdnbbsr.s3waas.gov.in/s36547884cea64550284728eb26b0947ef/uploads/2026/09/202609241754770724.pdf', 'Form VI', 'Form I (with attendance columns)', 'No separate muster roll: attendance is in Form I', None),
}
OSH_RULES = {  # leave register, unified annual return (form, due note)
    'AP': ('2026-08-07', 'Andhra Pradesh OSH and Working Conditions Rules, 2026 (G.O.Rt. No. 146, gazette 07-08-2026)', 'https://www.sgcms.com/regulatory-updates/wp-content/uploads/2026/08/OSHWC-Andhra-Pradesh-Rules-2026_07.08.2026.pdf', 'Form X', 'Form XII (calendar year), filed by 1 February; contractors file Form XII(A) half-yearly', None),
    'GJ': ('2025-11-21', 'Gujarat OSH and Working Conditions Rules, 2025 (Gazette Extra No. 87, 12-06-2025)', 'https://employmentlaw.lkslaw.com/file/updates/gujarat-osh-rules.pdf', 'Form 19', 'Form 23 (calendar year)', 'r.37 says by 1 February; the form says 28/29 February.'),
    'RJ': ('2026-06-30', 'Rajasthan OSH and Working Conditions Rules, 2026 (S.O. 23, 30-06-2026)', 'https://labour.rajasthan.gov.in/notification.aspx', 'Form 19', 'Form 22, filed by 1 February; contractors file Form 23 within 30 days of each half-year', None),
    'AR': ('2025-11-21', 'Arunachal Pradesh OSH and Working Conditions Rules, 2022 (gazette 02-05-2022, in force 21-11-2025)', 'https://employmentlaw.lkslaw.com/file/updates/state-rules/arunachal-pradesh/arunachal-osh-rules-2022.pdf', 'Form XI', 'Form IX', 'r.21(2) says by 1 February; the form says 28/29 February.'),
    'UP': ('2026-08-27', 'Uttar Pradesh OSH and Working Conditions Rules, 2026 (No. 962/XXXVI-03-2026, 27-08-2026)', 'https://simpliance.in/India/LEI/govt_notification/uttar-pradesh/final-notification-of-the-uttar-pradesh-occupational-safety-health-and-working-conditions-rules-2026-10211', 'Form 15', 'Form-17, filed by 31 January', None),
    'LA': ('2026-09-02', 'Ladakh OSH and Working Conditions Rules, 2026 (S.O. 238, gazette 02-09-2026)', 'https://egazette.ladakh.gov.in/WriteReadData/2026/1819.pdf', 'Form-19', 'Form-21 (calendar year), filed by 28 February', 'The commencement clause ties the rules to the Code\'s enforcement date (retroactive on its face).'),
    'LD': ('2026-04-27', 'Lakshadweep OSH and Working Conditions Rules, 2026 (Gazette Vol. LXII No. 07, 27-04-2026)', 'https://cdn.s3waas.gov.in/s358238e9ae2dd305d79c2ebc8c1883422/uploads/2026/04/17774444922586.pdf', 'Form 17', 'Form-19, filed by 28 February', None),
    'JK': ('2026-08-12', 'Jammu and Kashmir OSH and Working Conditions Rules, 2026 (S.O. 224, 12-08-2026; gazette date not confirmed)', 'https://law.jk.gov.in/download.php?id=6472', 'Form XI', 'Form IX', 'Gazette date not confirmed; r.25 says by 1 February, the form says 28/29 February.'),
    'MZ': ('2026-08-13', 'Mizoram OSH and Working Conditions Rules, 2026 (Gazette Extra Ex-674, 13-08-2026)', 'https://simpliance.in/stream/file/dXBsb2Fkcy9nb3Z0bm90aWZpY2F0aW9uL05vdGlmaWNhdGlvbiBvZiB0aGUgTWl6b3JhbSBPY2N1cGF0aW9uYWwgU2FmZXR5LCBIZWFsdGggYW5kIFdvcmtpbmcgQ29uZGl0aW9ucyBSdWxlcywgMjAyNi5wZGY=', 'Form XIV', 'Form XIX', 'r.34 says by 1 February; the form says 28/29 February.'),
}
SS_RULES = {  # register of women employees
    'AP': ('2026-07-07', 'Code on Social Security (Andhra Pradesh) Rules, 2026 (G.O.Rt. No. 126, 07-07-2026)', 'https://simpliance.in/download/file/dXBsb2Fkcy9nb3Z0bm90aWZpY2F0aW9uL05vdGlmaWNhdGlvbiBvZiB0aGUgQ29kZSBvbiBTb2NpYWwgU2VjdXJpdHkgKEFuZGhyYSBQcmFkZXNoKSBSdWxlcywgMjAyNi5wZGY=', 'Form XX'),
    'AR': ('2025-11-21', 'Arunachal Pradesh Code on Social Security Rules, 2021 (gazette 23-12-2021, in force 21-11-2025)', 'https://employmentlaw.lkslaw.com/file/updates/state-rules/arunachal-pradesh/arunachal-ss-rules-2021.pdf', 'Form XXXIII'),
    'BR': ('2026-07-01', 'Social Security (Bihar) Rules, 2026 (Bihar Gazette Extra, 01-07-2026)', 'https://simpliance.in/India/LEI/govt_notification/Bihar/notification-of-the-social-security-bihar-rules-2026-9827', 'Form XXI'),
    'GJ': ('2025-11-21', 'Gujarat Code on Social Security Rules, 2023 (Gazette Extra No. 306, 30-12-2023; amended 13-02-2026)', 'https://employmentlaw.lkslaw.com/file/updates/gujarat-ss-rules.pdf', 'Form XXXII'),
    'LA': ('2026-08-03', 'Code on Social Security (Ladakh) Rules, 2026 (S.O. 224)', 'https://egazette.ladakh.gov.in/WriteReadData/2026/1786.pdf', 'Form XXI'),
    'UP': ('2026-08-27', 'Uttar Pradesh Code on Social Security Rules, 2026 (No. 1431/XXXVI-3-2026, 27-08-2026)', 'https://simpliance.in/India/LEI/govt_notification/uttar-pradesh/final-notification-of-the-uttar-pradesh-code-on-social-security-rules-2026-10207', 'Form XXXVI'),
}

def _variant(state, frm, title, url, form, legal_review=None, note=None, code=None):
    v = {'sphere': 'state', 'states': [state], 'from': frm, 'form': form, 'legal_basis': title, 'confidence': _conf(url), 'sources': [_src(title, url)], 'verified_on': SV}
    if code: v['code'] = code
    if legal_review: v['legal_review'] = legal_review
    if note: v['note'] = note
    return v

def _insert(key, variants):
    vs = C[key]['variants']
    at = next(i for i, v in enumerate(vs) if v.get('sphere') == 'state')
    C[key]['variants'] = vs[:at] + variants + vs[at:]

_insert('employee_register', [_variant(st, d[0], d[1], d[2], d[3], d[6]) for st, d in WAGES_RULES.items()])
_insert('wage_register', [_variant(st, d[0], d[1], d[2], d[4], d[6]) for st, d in WAGES_RULES.items()])
_insert('muster_roll', [_variant(st, d[0], d[1], d[2], d[5], d[6] if 'not cited' not in d[5] else 'Form XIII is appended to the rules but no rule cites it.') for st, d in WAGES_RULES.items()])
_insert('leave_register', [_variant(st, d[0], d[1], d[2], d[3], d[5]) for st, d in OSH_RULES.items()])
_insert('annual_return', [_variant(st, d[0], d[1], d[2], d[4], d[5], note='Due date and form from the state\'s rules; PeopleOS\'s due date follows the central return.') for st, d in OSH_RULES.items()])
_insert('maternity_register', [_variant(st, d[0], d[1], d[2], d[3]) for st, d in SS_RULES.items()])
