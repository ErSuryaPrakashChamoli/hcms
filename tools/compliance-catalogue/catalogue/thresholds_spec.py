# Dated applicability thresholds of the state shops and establishments Acts (se-top15.md / se-rest.md, 2026-10-26).
# Each window: from/to (inclusive, period-end dates), min workers, measure, basis, confidence. Before the first window
# with a 'from', or where a state has no entry, no threshold applies.
def w(min_, basis, confidence, frm=None, to=None, measure='employed_any_day'):
    d = {'min': min_, 'measure': measure, 'basis': basis, 'confidence': confidence}
    if frm: d['from'] = frm
    if to: d['to'] = to
    return d

T = {
    'MH': [w(20, 'Mah. Ordinance VIII of 2025 (Gazette 01-10-2025; s.1(3): ten → twenty), from 1 Oct 2025; the replacement Bill LXXXIX of 2025 was not found enacted, so the Ordinance may have lapsed', 'LEGAL_REVIEW_REQUIRED', frm='2025-10-01'),
           w(10, 'Maharashtra S&E (RE&CS) Act, 2017 s.1(3) (ten or more workers)', 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED', to='2025-09-30')],
    'GJ': [w(20, 'Gujarat Act 1 of 2026 (assent and gazette 27-02-2026; s.1(3): ten → twenty), deemed from 16 Dec 2025', 'OFFICIAL_PRIMARY_SOURCE_VERIFIED', frm='2025-12-16'),
           w(10, 'Gujarat S&E (RE&CS) Act, 2019 s.1(3) (ten or more workers)', 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED', to='2025-12-15')],
    'HR': [w(20, 'Haryana Act 13 of 2026 (assent 28-01-2026; Gazette Extra No.20, 05-02-2026), new s.1(5), deemed from 12 Nov 2025', 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED', frm='2025-11-12')],
    'PB': [w(20, 'Punjab Act 12 of 2025 (assent 06-08-2025), in force 29 Aug 2025 (read in a Gazette of India issue whose signature validates)', 'OFFICIAL_PRIMARY_SOURCE_VERIFIED', frm='2025-08-29')],
    'CH': [w(20, 'Punjab Act 12 of 2025 extended to Chandigarh by S.O. 5647(E), 5 Dec 2025 (Gazette of India Extra No.5459; signature valid)', 'OFFICIAL_PRIMARY_SOURCE_VERIFIED', frm='2025-12-05')],
    'UP': [w(20, 'UP Dookan Aur Vanijya Adhishthan (Sanshodhan) Vidheyak, 2025 (UP Extra Gazette 30-12-2025), deemed from 19 Nov 2025 (gazette print on a non-government host)', 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED', frm='2025-11-19')],
    'OD': [w(20, 'Odisha S&CE (Amendment) Ordinance 03 of 2025 / Bill, new s.1(2-a), deemed from 8 Nov 2025; enactment not confirmed', 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED', frm='2025-11-08')],
    'TR': [w(20, 'Tripura S&E (Seventh Amendment) Act, 2025 (Act 11 of 2025; assent 01-11-2025, gazette and in force 06-11-2025), new s.1(4)', 'OFFICIAL_PRIMARY_SOURCE_VERIFIED', frm='2025-11-06')],
    'CG': [w(20, 'CG S&E (RE&CS) (Amendment) Act, 2025 (Act 11 of 2026, published and in force 8 Jul 2026), s.1(3) (gazette print on a non-government host)', 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED', frm='2026-07-08'),
           w(10, 'CG S&E (RE&CS) Act, 2017, in force 13 Feb 2025 (ten or more workers)', 'SECONDARY_SOURCE_ONLY', frm='2025-02-13', to='2026-07-07')],
    'UK': [w(20, 'Uttarakhand S&E (RE&CS) (Amendment) Act, 2026 (Act 06 of 2026), s.1(2), from 17 Apr 2026', 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED', frm='2026-04-17'),
           w(10, 'Uttarakhand S&E (RE&CS) Act, 2017 s.1(2) (ten or more workers)', 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED', to='2026-04-16')],
    'MZ': [w(5, 'Mizoram S&E (RE&CS) Act, 2010 s.1(2) (five or more workers); Bill 64 of 2023 (five → ten) passed, assent not confirmed', 'LEGAL_REVIEW_REQUIRED')],
    'AN': [w(20, 'A&N S&E Regulation 10 of 2025 (Gazette of India No.47, 19-11-2025; signature valid): 20 or more persons; commencement notification not found', 'LEGAL_REVIEW_REQUIRED', frm='2025-11-19')],
    'SK': [w(21, 'Sikkim notification No.11/LD/2025 (Gazette Extra No.457, 06-12-2025): establishments with 20 or fewer employees exempt from the Act', 'OFFICIAL_PRIMARY_SOURCE_VERIFIED', frm='2025-12-06')],
    'NL': [w(20, 'Nagaland S&E (1st Amendment) Bill, 2026 (passed 10-03-2026; gazette Pt V 13-03-2026): duties for 20 or more persons; assent not confirmed', 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED', frm='2026-03-13')],
    'DH': [w(10, 'DNH&DD S&E (RE&CS) Rules, 2025 (registration for 10+ workers), from 24 Jun 2025; 20 once Regulation 14 of 2025 commences (not notified)', 'SECONDARY_SOURCE_ONLY', frm='2025-06-24')],
}
