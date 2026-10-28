# Shops & establishments catalogue spec (from se-top15.md / se-rest.md research, 2026-10-26).
# reg tuple: (key, builder, title, form, rule, confidence)  builder None = checklist
# builders: ER employee_register, MR muster_roll, WR wage_register, DR deduction_register, LR leave_register,
#           OT overtime_register, CMW combined_muster_wages
B = {'ER': ('employee_register', 'register'), 'MR': ('muster_roll', 'monthly'), 'WR': ('wage_register', 'monthly'),
     'DR': ('deduction_register', 'monthly'), 'LR': ('leave_register', 'register'), 'OT': ('overtime_register', 'monthly'),
     'CMW': ('combined_muster_wages', 'monthly')}

S = {}
def st(code, name, act, registers=(), returns=(), checklist=(), min_emp=None, note='', summary_registers='', summary_returns=''):
    S[code] = dict(name=name, act=act, registers=list(registers), returns=list(returns), checklist=list(checklist), min=min_emp, note=note,
                   summary_registers=summary_registers, summary_returns=summary_returns)

st('MH', 'Maharashtra', 'Maharashtra Shops and Establishments (Regulation of Employment and Conditions of Service) Act, 2017 and Rules, 2018',
   [('se_muster_wages', 'CMW', 'Muster roll-cum-wages register', 'Form Q', 'r.26(1)', 'primary'),
    ('se_leave_book', 'LR', 'Leave book and notice of accumulated leave', 'Form O; Form P', 'r.19–20', 'primary')],
   [('se_annual_return', 'Annual return (shops and establishments)', 'Form R', 'r.27', {'months_after': 2, 'day': 'end'}, 'uploaded online within two months of the calendar year (end of February)', 'primary')],
   min_emp=20, note='From 1 Oct 2025 (Ordinance VIII of 2025) the Act applies to establishments with 20 or more workers; smaller ones file an intimation (Form F). The replacement Bill LXXXIX of 2025 was not found enacted, so the Ordinance may have lapsed: legal review.',
   summary_registers='Form Q muster roll-cum-wages; Form O leave book; Form P accumulated-leave notice; Forms M and N notices', summary_returns='Form R annual return by end-February')
st('KA', 'Karnataka', 'Karnataka Shops and Commercial Establishments Act, 1961 and Rules, 1963',
   [('se_muster_wages', 'CMW', 'Combined muster roll-cum-register of wages', 'Form T', 'Karnataka S&CE Rules, 1963', 'secondary'),
    ('se_leave', 'LR', 'Leave with wages register', 'Form F', 'r.8(1)', 'official')],
   [('se_annual_return', 'Combined annual return', 'Form U (e-Karmika)', 'Karnataka S&CE Rules, 1963', {'months_after': 1, 'day': 'end'}, 'online by 31 January for the previous calendar year; also covers the state MW, PW, CLRA and maternity returns', 'secondary')],
   note='Amendment Act, 2026: establishments with 10+ workers registered under the OSH Code are excluded from the Act; registration valid until closure.',
   summary_registers='Form T combined muster-cum-wages; Form F leave with wages; Form H leave book; Form Q appointment order', summary_returns='Form U combined annual return by 31 January')
st('TN', 'Tamil Nadu', 'Tamil Nadu Shops and Establishments Act, 1947 and Rules, 1948',
   [('se_persons_employed', 'ER', 'Register of persons employed', 'Form U', 'r.16(1)(a)', 'primary'),
    ('se_employment', 'MR', 'Register of employment', 'Form V', 'r.16(1)(a)', 'primary'),
    ('se_wages', 'WR', 'Register of wages', 'Form W', 'r.16(1)(a)', 'primary'),
    ('se_leave', 'LR', 'Register of leave and social security benefits', 'Form X', 'r.16(1)(a)', 'primary')],
   [('se_annual_return', 'Combined annual return', 'Form ZC', 'r.16-D (G.O. Ms. No.93, 29 Aug 2025)', {'months_after': 1, 'day': 'end'}, 'online on or before 31 January for the previous calendar year', 'primary')],
   note='Registration required for establishments with 10 or more workers (from 2 Jul 2024). Registers may be kept electronically or manually.',
   summary_registers='Forms U, V, W and X (electronic or manual)', summary_returns='Form ZC combined annual return by 31 January')
for code, name, conf in (('TG', 'Telangana', 'secondary'), ('AP', 'Andhra Pradesh', 'secondary')):
    st(code, name, f'{name} Shops and Establishments Act, 1988 and Rules, 1990',
       [('se_employment', 'MR', 'Register of employment', 'Form XXII', 'r.29(1)', conf),
        ('se_wages', 'WR', 'Register of wages', 'Form XXIII', 'r.29(2)', conf),
        ('se_leave', 'LR', 'Register of leave granted', 'Form XXV', 'r.29(6)', conf),
        ('se_fines_deductions', 'DR', 'Registers of fines, deductions for damage or loss, and advances', 'Forms X, XI and XII', 'r.17–18', conf)]
       + ([('se_integrated_register', 'CMW', 'Integrated register — muster roll-cum-register of wages, deductions, overtime and advances', 'Forms II and III (G.O.Ms.No.6, 2019)', 'G.O.Ms.No.6 LET&F (Labour) of 02-03-2019, under r.29 and r.33', 'primary')] if code == 'TG' else []),
       ([('se_periodical_return', 'Periodical return (quarterly)', 'Form XXVII', 'r.33', {'months_after': 1, 'day': 10}, 'by the 10th of the month after each quarter (employers with 10+ employees)', conf, 'quarterly', 10)] if code == 'TG' else []),
       ([('se_integrated_return', 'Integrated annual return under labour laws', 'Form I (G.O.Ms.No.6, 2019)', 'G.O.Ms.No.6 LET&F (Labour) of 02-03-2019 (supersedes G.O.Ms.No.23 of 2016)', 'yearly', 'financial', {'months_after': 1, 'day': 30}, 'to the Inspector by 30 April for the financial year ending 31 March', 'primary')] if code == 'TG' else
        [('se_combined_return', 'Combined annual return under labour laws', 'Form B', 'A.P. Act 10 of 2015 s.4(6); G.O.Ms.No.11, 2019', 'yearly', 'calendar', None, 'online (due date not confirmed)', 'secondary')]),
       note=('An employer who keeps the integrated register (Forms II and III) and files the Form I annual return is not liable to penalty under the listed Acts (G.O.Ms.No.6 of 2019). The rules\' form letters (XXII–XXVII) were not read in an official text. Establishments with no employees are exempt (G.O.Ms.No.11 of 2009).' if code == 'TG' else 'The 2025 amendment (A.P. Act 17 of 2025) exempting establishments with fewer than 20 workers from most of the Act has been in force since 3 Nov 2025 (G.O.Ms.No.23).'),
       summary_registers='Forms XXII employment, XXIII wages, XXV leave, X–XII fines / deductions / advances, XXVI appointment letter',
       summary_returns=('Form XXVII quarterly (10+ employees); integrated annual return' if code == 'TG' else 'Form B combined annual return (Act 10 of 2015)'))
st('DL', 'Delhi', 'Delhi Shops and Establishments Act, 1954 and Rules, 1954',
   [('se_wages', 'CMW', 'Register of wages, deductions, overtime and advances, with an attendance sheet', 'Form G (substituted 2021)', 'r.14(1)', 'official')],
   note='Forms H and I were omitted in 2021 (No.15(52)/Lab/2021/4506, Gazette Extra Pt IV No.330, 15-11-2021); every establishment is covered, with no headcount threshold. No annual return.', summary_registers='Form G consolidated register', summary_returns='None')
for code, name, since, act in (('HR', 'Haryana', '12 Nov 2025', 'Haryana Shops and Commercial Establishments Act, 1958 and Punjab S&CE Rules, 1958 as applicable'),
                               ('PB', 'Punjab', '29 Aug 2025', 'Punjab Shops and Commercial Establishments Act, 1958 and Rules, 1958'),
                               ('CH', 'Chandigarh', '5 Dec 2025', 'Punjab Shops and Commercial Establishments Act, 1958 and Rules, 1958 as applicable to Chandigarh')):
    st(code, name, act,
       [('se_employees', 'ER', 'Register of employees', 'Form C', 'r.5(1)', 'primary' if code == 'HR' else 'official'),
        ('se_wages', 'WR', 'Register of wages', 'Form D', 'r.5(2)', 'primary' if code == 'HR' else 'official'),
        ('se_deductions', 'DR', 'Register of deductions', 'Form E', 'r.5(3)', 'primary' if code == 'HR' else 'official')],
       checklist=([('se_integrated_return', 'Single integrated annual return under labour laws', 'Online (hrylabour.gov.in)', 'Labour Department order of 16 Jun 2016', 'yearly', 'calendar', None, 'online (due date not confirmed)', 'secondary')] if code == 'HR' else []),
       min_emp=20, note=f'From {since} the Act applies to establishments with 20 or more workers; smaller ones file an intimation.' + {'HR': ' Registers in English or Hindi, signed by the employer and the Inspector, kept 3 years.', 'PB': ' The language (English and Punjabi) and two-year retention are not verified.', 'CH': ' Forms C, D and E are read in the 1958 rules text on hrylabour.gov.in, not a Chandigarh text; language and retention are not verified. Establishments registered under the OSH Code are exempt from registration.'}[code],
       summary_registers='Forms C employees, D wages, E deductions', summary_returns=('Single integrated annual return' if code == 'HR' else 'None'))
st('UP', 'Uttar Pradesh', 'Uttar Pradesh Dookan Aur Vanijya Adhishthan Adhiniyam, 1962 and Niyamavali, 1963',
   [('se_attendance_wages', 'CMW', 'Register of attendance and wages', 'Form G', 'r.18(1)', 'official'),
    ('se_leave', 'LR', 'Register of leave', 'Form H', 'r.18(1)', 'official'),
    ('se_deductions_fines', 'DR', 'Registers of deductions and fines', 'Forms D (deductions) and E (fines)', 'r.12(8), r.13(1)', 'official')],
   checklist=[('se_close_days', 'Notice of close days', 'Form B', 'r.8', 'yearly', 'calendar', None, 'displayed, with a copy to the Inspector within two days of first display (not an annual filing)', 'official')],
   min_emp=20, note='From 19 Nov 2025 the Act applies to establishments with 20 or more employees (Sanshodhan Vidheyak 2025, UP Extra Gazette 30-12-2025). Form D wherever deductions are made; Form E where fines are imposed; registers kept six years (r.18(5)). Read in the UP Niyamavali, 1963 consolidation hosted by the Uttarakhand Labour Department; sources conflict on Form D above 25 employees.', summary_registers='Form G attendance and wages; Form H leave; Forms D and E deductions and fines', summary_returns='None (Form B close-days notice, copied to the Inspector)')
st('GJ', 'Gujarat', 'Gujarat Shops and Establishments (Regulation of Employment and Conditions of Service) Act, 2019 and Rules, 2020',
   [('se_muster_wages', 'CMW', 'Muster roll-cum-wages register', 'Form P (or Forms B and D of the Gujarat Ease of Compliance Rules, 2017)', 'r.26(1)', 'primary'),
    ('se_leave_book', 'LR', 'Leave book', 'Form N', 'r.17', 'primary')],
   [('se_annual_return', 'Annual return (shops and establishments)', 'Form Q', 'r.27', {'months_after': 1, 'day': 'end'}, 'within one month after 31 December, for the previous calendar year', 'primary')],
   min_emp=20, note='From 16 Dec 2025 (Gujarat Act 1 of 2026) the Act applies to establishments with 20 or more workers; smaller ones file an intimation. Rules r.3 and r.6 still say "ten".', summary_registers='Form P muster-cum-wages; Form N leave book; Form M identity card', summary_returns='Form Q annual return by 31 January')
st('WB', 'West Bengal', 'West Bengal Shops and Establishments Act, 1963 and Rules, 1964',
   [('se_employees', 'ER', 'Register of employees', 'Form W', 'r.52', 'secondary'),
    ('se_hours', 'MR', 'Register of hours of work and rest intervals', 'Form I or I(1)', 'r.13', 'official'),
    ('se_pay', 'WR', 'Pay register', 'Form M', 'r.30', 'secondary'),
    ('se_leave', 'LR', 'Leave register', 'Form J', 'r.21', 'secondary'),
    ('se_overtime', 'OT', 'Register of overtime', 'Form U', 'r.40', 'secondary')],
   note='No annual return found under the Act.', summary_registers='Forms W employees, I hours, M pay, J leave, U overtime, X appointment letter', summary_returns='None found')
st('KL', 'Kerala', 'Kerala Shops and Commercial Establishments Act, 1960 and Rules, 1961',
   checklist=[('se_registers', 'Registers of employment, wages, attendance, leave and overtime', 'Form letters not confirmed (Form F combined register and Form G leave register reported)', 'Act s.30', 'register', None, None, 'kept current; the particulars are in the PeopleOS employee register, muster roll and wage register', 'unverified')],
   note='The return under r.12A was omitted in 2022; registration renews automatically on fee payment (LCAS).', summary_registers='Employment, wages, attendance, leave, overtime (forms not confirmed)', summary_returns='None (r.12A omitted 2022)')
st('RJ', 'Rajasthan', 'Rajasthan Shops and Commercial Establishments Act, 1958 and Rules, 1959',
   checklist=[('se_registers', 'Register of employment, hours of work and overtime', 'Forms 11, 12 and 14 (reported)', 'Rajasthan S&CE Rules, 1959 r.22', 'register', None, None, 'kept current; the particulars are in the PeopleOS employee register and muster roll', 'unverified')],
   note='Establishments with up to 10 workers are exempt from registration (19 Aug 2025). No annual return found.', summary_registers='Forms 11, 12, 14 (not confirmed)', summary_returns='None found')
st('MP', 'Madhya Pradesh', 'Madhya Pradesh Shops and Establishments Act, 1958 and Rules, 1959',
   [('se_register', 'CMW', 'Register of employees (attendance, wages, overtime, fines and deductions)', 'Form N', 'r.20(1)', 'official'),
    ('se_leave', 'LR', 'Register of leave', 'Form I (leave book Form J; refusals Form K)', 'r.13', 'official')],
   note='No employer annual return under the Rules.', summary_registers='Form N combined register; Forms I, J, K leave', summary_returns='None')
st('OD', 'Odisha', 'Odisha Shops and Commercial Establishments Act, 1956 and Rules, 1958',
   [('se_muster_wages', 'CMW', 'Combined muster roll-cum-register of wages', 'Form 10', 'Odisha S&CE Rules, 1958', 'unverified'),
    ('se_leave', 'LR', 'Leave register', 'Form 9', 'Odisha S&CE Rules, 1958', 'unverified'),
    ('se_fines_deductions', 'DR', 'Register of fines, damages, deductions and advances', 'Form 11', 'Odisha S&CE Rules, 1958', 'unverified'),
    ('se_overtime', 'OT', 'Overtime register', 'Form 12', 'Odisha S&CE Rules, 1958', 'unverified')],
   min_emp=20, note='From 8 Nov 2025 (ordinance) the Act applies to establishments with 20 or more employees. Form numbers come from one secondary source.', summary_registers='Forms 9, 10, 11, 12 (not confirmed)', summary_returns='None found')
st('AS', 'Assam', 'Assam Shops and Establishment Act, 2022 (Act 3 of 2025)',
   checklist=[('se_registers', 'Registers and annual return under the Assam Shops and Establishment Act', 'Not yet prescribed (no rules found)', 'Act s.25–26', 'register', None, None, 'establishments with 10 or more employees follow the OSH Code registers', 'unverified')],
   note='Since 2 Sep 2026, establishments with 10+ employees come under the OSH Code; smaller ones file an online intimation.', summary_registers='As prescribed (rules not found)', summary_returns='Annual return (form not prescribed)')
st('BR', 'Bihar', 'No shops and establishments Act in force (repealed by Bihar Act 15 of 2026)',
   note='Shops and establishments follow the OSH Code and the Bihar OSH rules; use the central registers.', summary_registers='None — use the Code registers', summary_returns='None')
st('CG', 'Chhattisgarh', 'Chhattisgarh Shops and Establishments (Regulation of Employment and Conditions of Service) Act, 2017 and Rules, 2021',
   [],
   [('se_annual_return', 'Annual return (shops and establishments)', 'Form-14', 'Chhattisgarh S&E Rules, 2021', {'months_after': 2, 'day': 14}, 'online within 45 days of the calendar year (14 February)', 'secondary')],
   [('se_registers', 'Registers under the Chhattisgarh Shops and Establishments Rules', 'Form numbers not confirmed', 'Chhattisgarh S&E Rules, 2021', 'register', None, None, 'the particulars are in the PeopleOS employee register, muster roll and wage register', 'unverified')],
   min_emp=20, note='From 8 Jul 2026 the Act applies to establishments with 20 or more workers.', summary_registers='As prescribed (forms not confirmed)', summary_returns='Form-14 annual return by 14 February')
st('GA', 'Goa', 'Goa Shops and Establishments Act, 1973 and Goa, Daman and Diu Rules, 1975',
   [('se_employment', 'MR', 'Register of employment', 'Form XX (Form XXI where hours are uniform)', 'r.31', 'official'),
    ('se_wages', 'WR', 'Register of wages', 'Form XXIII', 'r.31', 'official'),
    ('se_leave', 'LR', 'Register of leave', 'Form XII (leave book Form XIII)', 'r.31', 'official'),
    ('se_fines_deductions', 'DR', 'Registers of fines, deductions for damage or loss, and advances', 'Forms IX, X and XI', 'r.31', 'official')],
   [('se_quarterly_return', 'Quarterly return (shops and establishments)', 'Form XXIX', 'r.34', {'months_after': 1, 'day': 10}, 'to the area Inspector by the 10th of the month after each quarter (5+ employees)', 'official', 'quarterly', 5)],
   note='The Goa Shops and Establishments (RE&CS) Act, 2025 awaits commencement. Registers bound and kept 3 years.', summary_registers='Forms XX employment, XXIII wages, XII leave, IX–XI fines / deductions / advances', summary_returns='Form XXIX quarterly by the 10th')
st('HP', 'Himachal Pradesh', 'Himachal Pradesh Shops and Commercial Establishments Act, 1969 and Rules, 1972',
   [('se_employees', 'ER', 'Register of employees', 'Form No. 8', 'r.14', 'secondary'),
    ('se_wages', 'WR', 'Register of wages', 'Form No. 9', 'r.14', 'secondary'),
    ('se_deductions', 'DR', 'Register of deductions', 'Form No. 10', 'r.14', 'secondary'),
    ('se_leave', 'LR', 'Register of leave with wages', 'Form No. 11', 'r.14', 'secondary')],
   note='Registration statement for establishments with 10+ employees (2026). No return found. Registers bound, kept 3 years.', summary_registers='Forms 8 employees, 9 wages, 10 deductions, 11 leave', summary_returns='None found')
st('JH', 'Jharkhand', 'Bihar Shops and Establishments Act, 1953 as adapted by Jharkhand and Jharkhand Rules, 2001',
   [('se_leave', 'LR', 'Leave with wages register', 'Form IX', 'r.14', 'secondary'),
    ('se_attendance_wages', 'CMW', 'Register of attendance, wages and overtime payment', 'Form X', 'r.17', 'secondary'),
    ('se_fines_deductions', 'DR', 'Register of fines and deductions', 'Form XI', 'r.19', 'secondary')],
   [('se_quarterly_return', 'Quarterly return (shops and establishments)', 'Form XIX', 'r.43', {'months_after': 1, 'day': 30}, 'within 30 days of the quarter end (10+ persons), unless the single online return is filed', 'secondary', 'quarterly', 10),
    ('se_annual_return', 'Annual return (shops and establishments)', 'Annual return; or the single online return (r.43A) by 30 June', 'r.42 and r.43A', {'months_after': 3, 'day': 31}, 'by 31 March for the previous calendar year (10+ persons)', 'secondary', 'yearly', 10)],
   summary_registers='Forms IX leave, X attendance-wages-overtime, XI fines and deductions, XXI service card', summary_returns='Form XIX quarterly; annual return by 31 March (or online return by 30 June)')
for code, name, conf, extra in (('JK', 'Jammu and Kashmir', 'secondary', 'The J&K Shops and Establishments Act, 2025 has commenced (S.O. 219, 06-08-2026; gazette date not confirmed); the 1966 Act is repealed and the 1968 Rules\' forms continue (s.35(a)). Registration is optional below 20; electronic records are allowed.'),
                                ('LA', 'Ladakh', 'conflicting', 'Which Act applies in Ladakh is unclear: the J&K 2025 Act does not extend to Ladakh, and the Kargil labour page cites a "1996" Act.')):
    st(code, name, 'Jammu and Kashmir Shops and Establishments Act, 1966 and Rules, 1968',
       [('se_register', 'CMW', 'Register of employees (attendance, overtime and wages)', 'Form L', 'r.20(1)', conf),
        ('se_leave', 'LR', 'Leave with wages register', 'Form G (leave book Form H)', 'r.11', conf)],
       note=extra + ' Registers are stamped by the Inspector and kept 3 years.', summary_registers='Form L combined register; Form G leave; Form P service card', summary_returns='None found')
st('MN', 'Manipur', 'Manipur Shops and Establishments (Regulation of Employment and Conditions of Service) Act, 2021 (legacy Rules, 1973)',
   [('se_attendance_wages', 'CMW', 'Register of attendance, overtime and wages', 'Form VII (1973 Rules)', 'r.17(3)', 'unverified'),
    ('se_fines_deductions', 'DR', 'Register of fines and deductions', 'Form II (1973 Rules)', 'r.5', 'unverified'),
    ('se_leave', 'LR', 'Leave with wages register', 'Form VI (1973 Rules)', 'r.17(2)', 'unverified')],
   [('se_annual_statement', 'Annual statement (shops and establishments)', 'Form III (1973 Rules)', 'r.15', {'months_after': 1, 'day': 'end'}, 'before the end of January', 'unverified')],
   note='Rules under the 2021 Act were not found; the 1973 forms are the best available.', summary_registers='Forms VII, II, VI (1973 Rules)', summary_returns='Form III annual statement by end-January (legacy)')
st('ML', 'Meghalaya', 'Meghalaya Shops and Establishment Act, 2003 and Rules, 2004',
   [('se_hours', 'MR', 'Register of hours of work and rest intervals', 'Form Q', 'r.51', 'official'),
    ('se_overtime', 'OT', 'Register of overtime work and payment of overtime wages', 'Form R', 'r.52', 'official'),
    ('se_employment', 'ER', 'Register of employment', 'Form S', 'r.53', 'official'),
    ('se_leave', 'LR', 'Register of leave and leave card', 'Form T', 'Meghalaya S&E Rules, 2004', 'official')],
   note='Registration for establishments with 5+ employees (2025). No return found.', summary_registers='Forms Q hours, R overtime, S employment, T leave, M appointment letter', summary_returns='None found')
st('MZ', 'Mizoram', 'Mizoram Shops and Establishments (Regulation of Employment and Conditions of Service) Act, 2010 and Rules, 2011',
   [('se_hours', 'MR', 'Register of hours of work and rest intervals', 'Form G', 'r.16', 'secondary'),
    ('se_overtime', 'OT', 'Register of overtime work and payment of overtime wages', 'Form H', 'r.17', 'secondary'),
    ('se_employment', 'ER', 'Register of employment', 'Form R', 'r.49', 'secondary'),
    ('se_leave', 'LR', 'Register of leave and leave card', 'Form S', 'r.50–51', 'secondary')],
   min_emp=5, note='Applies to establishments with 5+ workers, but Bill 64 of 2023 (five to ten) passed and its assent is not confirmed: legal review. OSH Code registration counts as registration (Ordinance 1 of 2026, in force 29-06-2026). No return found.', summary_registers='Forms G hours, H overtime, R employment, S leave, O appointment letter', summary_returns='None found')
st('NL', 'Nagaland', 'Nagaland Shops and Establishments Act, 1982 and Rules, 2015',
   [('se_hours', 'MR', 'Register of hours of work and rest intervals', 'Form H', 'r.14', 'secondary'),
    ('se_leave', 'LR', 'Leave register', 'Form I', 'r.15', 'secondary'),
    ('se_pay', 'WR', 'Pay register', 'Form J', 'r.25', 'secondary'),
    ('se_overtime', 'OT', 'Register of overtime work and payment of overtime wages', 'Form N', 'r.29', 'secondary'),
    ('se_employees', 'ER', 'Register of employees', 'Form S', 'r.54', 'secondary')],
   note='Registration renewed every 12 months, online. The 1st Amendment Bill, 2026 (passed 10-03-2026, gazette Pt V 13-03-2026) limits the Act\'s duties to establishments with 20 or more persons; registration applies to all. Assent not confirmed. No return found.', summary_registers='Forms H hours, I leave, J pay, N overtime, S employees, T appointment letter', summary_returns='None found')
st('SK', 'Sikkim', 'Sikkim Shops and Commercial Establishments Act, 1983 and Rules, 1984',
   [('se_employment', 'MR', 'Register of employment', 'Form I (Form K where hours are uniform)', 'r.15', 'secondary'),
    ('se_leave', 'LR', 'Register of leave', 'Form N (leave book Form O)', 'r.15', 'secondary')],
   note='Establishments with 20 or fewer employees are exempt from the whole Act (No.11/LD/2025, Sikkim Gazette Extra No.457, 06-12-2025); they intimate their details in Form I within three months. No return found. Records kept 2 years.', summary_registers='Forms I/K employment, N leave, O leave book', summary_returns='None found')
st('TR', 'Tripura', 'Tripura Shops and Establishments Act, 1970 and Rules, 1970',
   [('se_hours', 'MR', 'Register of hours of work and rest intervals', 'Form I', 'r.13', 'secondary'),
    ('se_leave', 'LR', 'Leave register', 'Form J', 'r.21', 'secondary'),
    ('se_pay', 'WR', 'Pay register', 'Form K', 'r.22', 'secondary'),
    ('se_overtime', 'OT', 'Register of overtime', 'Form S', 'r.32', 'secondary'),
    ('se_employees', 'ER', 'Register of employees', 'Form U', 'r.44', 'secondary')],
   min_emp=20, note='From 6 Nov 2025 (Tripura Act 11 of 2025) the Act applies to establishments with 20 or more persons; registration under s.16 was deleted from 24 May 2021. No return found.', summary_registers='Forms I hours, J leave, K pay, S overtime, U employees, V appointment letter', summary_returns='None found')
st('UK', 'Uttarakhand', 'Uttarakhand Shops and Establishments (Regulation of Employment and Conditions of Service) Act, 2017',
   checklist=[('se_registers', 'Registers and annual return under the Uttarakhand Shops and Establishments Act', 'Not yet prescribed (no rules found)', 'Act s.18–19', 'register', None, None, 'records may be kept electronically; the particulars are in the PeopleOS registers', 'unverified')],
   min_emp=20, note='From 17 Apr 2026 the Act applies to establishments with 20 or more workers.', summary_registers='As prescribed (rules not found)', summary_returns='Annual return (form not prescribed)')
st('AR', 'Arunachal Pradesh', 'No shops and establishments Act found', note='Use the OSH Code registers (Arunachal Pradesh OSH Rules, 2022). Confirm with the state labour department.', summary_registers='None found', summary_returns='None found')
st('PY', 'Puducherry', 'Puducherry Shops and Establishments Act, 1964 and Rules, 1964',
   [('se_integrated_employees', 'ER', 'Integrated register — employee particulars', 'Form II (G.O.Ms.No.6/AIL/Lab/G/2021)', 'Integrated register order, 2021', 'primary'),
    ('se_integrated_muster_wages', 'CMW', 'Integrated register — muster roll-cum-register of wages, deductions, overtime and advances', 'Form III (G.O.Ms.No.6/AIL/Lab/G/2021)', 'Integrated register order, 2021', 'primary')],
   [('se_consolidated_return', 'Consolidated annual return under labour laws', 'Form I (online)', 'S&E Act s.53(1) and r.22(1); G.O.Ms.No.6/2021', {'months_after': 1, 'day': 30}, 'online by 30 April for the financial year', 'secondary', 'yearly', None, 'financial')],
   note='The integrated register covers the S&E Act and nine other labour laws (G.O.Ms.06/AIL/Lab./G/2021, Gazette Pt I Extra No.292, 30-11-2021); keeping it is optional ("may"). Registration renews by yearly online self-certification (G.O.Ms.14/2022).', summary_registers='Integrated register Forms II and III', summary_returns='Form I consolidated annual return by 30 April')
st('AN', 'Andaman and Nicobar Islands', 'Andaman and Nicobar Islands Shops and Establishments Regulation, 2004 and Rules, 2005',
   [('se_wages', 'WR', 'Register of wages', 'Form I (wage slip Form II)', 'r.11', 'secondary'),
    ('se_muster', 'MR', 'Muster roll', 'Form III', 'r.11', 'secondary'),
    ('se_overtime', 'OT', 'Register of overtime', 'Form IV', 'r.11', 'secondary'),
    ('se_leave', 'LR', 'Register of leave', 'Form V', 'r.11', 'secondary'),
    ('se_employees', 'ER', 'Register of employees', 'Form VI', 'r.11', 'secondary')],
   note='Regulation 10 of 2025 (Gazette of India No.47, 19-11-2025) limits the Regulation to establishments with 20 or more persons; those with 10–19 come under the OSH Code (r.12A, 2026). Its commencement notification was not found. The Rules also prescribe a wage slip (Form II, a day before payment) and a food-grain concession register (Form VII). Forms read only in a reprint. No return found.', summary_registers='Forms I wages, III muster, IV overtime, V leave, VI employees', summary_returns='None found')
st('DH', 'Dadra and Nagar Haveli and Daman and Diu', 'Gujarat Shops and Establishments (RE&CS) Act, 2019 as extended, and DNH&DD Rules, 2025',
   [('se_muster_wages', 'CMW', 'Combined muster roll-cum-wage register', 'Form P', 'DNH&DD S&E Rules, 2025', 'secondary'),
    ('se_leave_book', 'LR', 'Leave book', 'Form N', 'DNH&DD S&E Rules, 2025', 'secondary')],
   [('se_annual_return', 'Annual return (shops and establishments)', 'Form Q', 'DNH&DD S&E Rules, 2025', {'months_after': 1, 'day': 'end'}, 'by 31 January', 'secondary')],
   min_emp=10, note='Applies to establishments with 10+ workers (20+ once Regulation 14 of 2025 commences). Records kept 5 years.', summary_registers='Form P combined muster-cum-wages; Form N leave book', summary_returns='Form Q annual return by 31 January')
st('LD', 'Lakshadweep', 'No shops and establishments law found', note='Use the OSH Code registers. Confirm with the UT labour department.', summary_registers='None found', summary_returns='None found')
