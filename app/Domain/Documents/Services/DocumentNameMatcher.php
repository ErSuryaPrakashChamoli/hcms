<?php

namespace App\Domain\Documents\Services;

use App\Domain\Documents\Models\DocumentType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Reads what a file is from its name, for the bulk upload of employee documents: "Aadhar card.pdf" is the organisation's
 * Aadhaar type, "10th marksheet.jpg" its 10th marksheet, "EMP001_PAN.pdf" its PAN card.
 *
 * A file name is cut into words (lower case, accents and punctuation dropped, "M.B.A" read as "mba"); the employee's code
 * and name and any date are left out. The words are then looked up in phrases built from the organisation's own active
 * document types (each type's name, its name without the bracketed part, the bracketed part, its code as words) and the
 * usual spellings of the standard types (ALIASES; by code, or by the profile field a type is linked to, so a renamed
 * Aadhaar type still answers to "adhar"). The longest phrase found wins; a phrase that only names a broad kind of paper
 * ("address proof", "degree", "photo": WEAK) counts only when nothing more specific is in the name. Two different types
 * found with phrases of the same length are not guessed between: the file waits for the person to choose. Without a
 * match, misspelt words are corrected to the nearest word the phrases use (one letter off, two in long words) and the
 * name is read again; then the name of the folder the file is in.
 *
 * Nothing here reads the file's content: the name decides, and the person sees and can change every match before
 * anything is uploaded.
 */
final class DocumentNameMatcher
{
    /**
     * The usual ways people name the standard document types' files (peopleos.documents.defaults codes). Written as
     * people type them; normalised like any file name.
     *
     * @var array<string, list<string>>
     */
    public const ALIASES = [
        // Identity
        'AADHAAR' => ['aadhaar', 'aadhar', 'adhar', 'adhaar', 'aadhaar card', 'aadhar card', 'adhar card', 'adhaar card', 'aadhaar letter', 'e aadhaar', 'eaadhaar', 'uid', 'uidai', 'uid card', 'masked aadhaar'],
        'PAN' => ['pan', 'pan card', 'pancard', 'permanent account number', 'e pan'],
        'PASSPORT' => ['passport', 'passport copy', 'passport front', 'passport back'],
        'DRIVING_LICENCE' => ['dl', 'driving licence', 'driving license', 'driving lic', 'driving licence card', 'licence', 'license'],
        'VOTER_ID' => ['voter id', 'voterid', 'voter card', 'voter id card', 'epic', 'epic card', 'election card', 'election id', 'election commission card'],
        'RATION_CARD' => ['ration card', 'rationcard'],
        'ADDRESS_PROOF' => ['address proof', 'address', 'electricity bill', 'utility bill', 'residence proof', 'permanent address proof', 'current address proof'],
        'OCI_CARD' => ['oci', 'oci card'],
        // Education
        'SSC_MARKSHEET' => ['10th', '10th marksheet', '10th mark sheet', '10th marks', '10th marks card', 'class 10', 'class 10th', 'class x', 'x marksheet', 'xth', 'ssc', 'ssc marksheet', 'sslc', 'sslc marksheet', 'matric', 'matriculation', 'secondary marksheet', 'high school', 'high school marksheet'],
        'SSC_CERTIFICATE' => ['10th certificate', '10th passing certificate', '10th pass certificate', 'ssc certificate', 'sslc certificate', 'matric certificate', 'high school certificate'],
        'HSC_MARKSHEET' => ['12th', '12th marksheet', '12th mark sheet', '12th marks', '12th marks card', 'class 12', 'class 12th', 'class xii', 'xii marksheet', 'xiith', 'hsc', 'hsc marksheet', 'intermediate', 'inter marksheet', 'intermediate marksheet', 'puc', 'puc marksheet', 'higher secondary', 'senior secondary'],
        'HSC_CERTIFICATE' => ['12th certificate', '12th passing certificate', '12th pass certificate', 'hsc certificate', 'intermediate certificate', 'puc certificate'],
        'ITI_CERTIFICATE' => ['iti', 'iti certificate', 'vocational certificate', 'ncvt certificate'],
        'DIPLOMA_MARKSHEET' => ['diploma marksheet', 'diploma marksheets', 'diploma marks'],
        'DIPLOMA_CERTIFICATE' => ['diploma', 'diploma certificate', 'polytechnic certificate'],
        'UG_MARKSHEET' => ['graduation marksheet', 'graduation marksheets', 'ug marksheet', 'degree marksheet', 'degree marksheets', 'semester marksheet', 'sem marksheet', 'consolidated marksheet',
            'btech marksheet', 'be marksheet', 'bcom marksheet', 'bsc marksheet', 'ba marksheet', 'bca marksheet', 'bba marksheet', 'transcript'],
        'UG_DEGREE' => ['graduation', 'graduation degree', 'graduation certificate', 'ug degree', 'bachelor', 'bachelors', 'bachelor degree', 'bachelors degree',
            'btech', 'b tech', 'btech degree', 'be degree', 'bcom', 'b com', 'bsc', 'b sc', 'ba degree', 'bca', 'bba', 'llb', 'mbbs', 'bpharm', 'barch'],
        'PROVISIONAL_DEGREE' => ['provisional', 'provisional degree', 'provisional certificate'],
        'PG_MARKSHEET' => ['pg marksheet', 'post graduation marksheet', 'postgraduation marksheet', 'masters marksheet', 'mba marksheet', 'mtech marksheet', 'mca marksheet', 'mcom marksheet', 'msc marksheet', 'ma marksheet'],
        'PG_DEGREE' => ['pg', 'pg degree', 'post graduation', 'postgraduation', 'post graduation degree', 'postgraduate', 'masters', 'masters degree', 'master degree',
            'mba', 'mba degree', 'pgdm', 'mtech', 'm tech', 'mca', 'mcom', 'm com', 'msc', 'm sc', 'ma degree', 'llm', 'me degree'],
        'PHD_DEGREE' => ['phd', 'doctorate', 'phd degree', 'doctoral degree'],
        'DEGREE' => ['degree', 'degree certificate', 'highest degree', 'highest qualification', 'qualification certificate'],
        'MIGRATION_CERTIFICATE' => ['migration', 'migration certificate'],
        'TRANSFER_CERTIFICATE' => ['tc', 'transfer certificate', 'school leaving certificate', 'leaving certificate', 'slc'],
        'EQUIVALENCE_CERTIFICATE' => ['equivalence certificate', 'degree equivalence'],
        'PROFESSIONAL_CERT' => ['certification', 'professional certificate', 'professional certification'],
        'PROFESSIONAL_LICENCE' => ['professional licence', 'professional license', 'registration certificate', 'council registration'],
        // Previous employment
        'PREVIOUS_OFFER' => ['previous offer', 'previous offer letter', 'previous appointment letter', 'last offer letter', 'old offer letter'],
        'EXPERIENCE_LETTER' => ['experience', 'experience letter', 'experience certificate', 'experience letters', 'service certificate', 'exp letter', 'exp certificate'],
        'RELIEVING' => ['relieving', 'relieving letter', 'reliving letter', 'relieve letter', 'release letter', 'relieving certificate'],
        'RESIGNATION_ACCEPTANCE' => ['resignation acceptance', 'resignation acceptance letter', 'resignation accepted'],
        'PAYSLIP' => ['payslip', 'payslips', 'pay slip', 'pay slips', 'salary slip', 'salary slips', 'salaryslip', 'last payslip', 'last 3 payslips', 'previous payslips'],
        'SALARY_STATEMENT' => ['bank statement', 'salary statement', 'salary account statement', 'salary credit statement'],
        'PREVIOUS_FNF' => ['previous fnf', 'previous full and final', 'previous f and f', 'last fnf'],
        // Tax
        'PREVIOUS_FORM16' => ['form 16', 'form16', 'previous form 16', 'form 16 previous employer'],
        'FORM_12BB' => ['form 12bb', '12bb', 'form12bb'],
        'INVESTMENT_PROOF' => ['investment proof', 'investment proofs', '80c', '80d', '80c proof', 'tax proof', 'tax proofs', 'tax saving proof'],
        'RENT_RECEIPTS' => ['rent receipt', 'rent receipts', 'rent agreement', 'rental agreement', 'hra proof', 'hra receipts'],
        'HOME_LOAN_CERTIFICATE' => ['home loan certificate', 'home loan interest certificate', 'home loan', 'housing loan certificate'],
        'TAX_RESIDENCY_CERT' => ['tax residency certificate', 'trc'],
        // Statutory
        'BANK_PROOF' => ['cancelled cheque', 'canceled cheque', 'cancel cheque', 'cancelled check', 'cheque', 'cheque leaf', 'bank proof', 'bank passbook', 'passbook', 'bank details', 'bank letter'],
        'UAN_CARD' => ['uan', 'uan card', 'epf passbook', 'pf passbook', 'pf card', 'uan letter'],
        'EPF_FORM_11' => ['form 11', 'form11', 'epf form 11', 'pf form 11', 'pf declaration'],
        'EPF_FORM_2' => ['form 2', 'form2', 'epf form 2', 'pf form 2', 'pf nomination'],
        'EPF_FORM_13' => ['form 13', 'form13', 'epf form 13', 'pf transfer'],
        'ESIC_CARD' => ['esic', 'esi', 'esic card', 'esi card', 'pehchan', 'e pehchan', 'pehchan card', 'esic declaration'],
        'GRATUITY_FORM_F' => ['form f', 'gratuity nomination', 'gratuity form', 'gratuity form f'],
        // Company
        'OFFER' => ['offer', 'offer letter', 'signed offer', 'signed offer letter', 'accepted offer letter'],
        'APPOINTMENT_LETTER' => ['appointment', 'appointment letter', 'signed appointment letter'],
        'EMPLOYMENT_AGREEMENT' => ['employment agreement', 'agreement', 'employment contract', 'contract', 'service agreement'],
        'JOINING_FORM' => ['joining form', 'joining kit', 'joining', 'joining report', 'joining letter'],
        'NDA' => ['nda', 'non disclosure', 'non disclosure agreement', 'confidentiality agreement'],
        'CODE_OF_CONDUCT' => ['code of conduct', 'coc', 'code of conduct acknowledgement'],
        'POLICY_ACK' => ['policy acknowledgement', 'policy acknowledgment', 'policy declaration'],
        'BGV_CONSENT' => ['bgv consent', 'bgv authorization', 'bgv authorisation', 'bgv form', 'background check consent', 'background verification consent'],
        'BGV_REPORT' => ['bgv', 'bgv report', 'background verification', 'background verification report', 'background check'],
        'POLICE_VERIFICATION' => ['police verification', 'police clearance', 'pcc', 'police certificate', 'police clearance certificate'],
        'CONFIRMATION_LETTER' => ['confirmation', 'confirmation letter'],
        'REVISION_LETTER' => ['increment letter', 'revision letter', 'salary revision letter', 'appraisal letter', 'hike letter', 'increment'],
        'PROMOTION_LETTER' => ['promotion', 'promotion letter'],
        'TRANSFER_LETTER' => ['transfer letter'],
        'DISCIPLINARY_LETTER' => ['warning letter', 'disciplinary letter', 'show cause', 'show cause notice', 'warning'],
        // Training, medical
        'TRAINING_CERTIFICATE' => ['training', 'training certificate', 'course certificate', 'completion certificate'],
        'FITNESS_CERTIFICATE' => ['medical', 'medical certificate', 'fitness certificate', 'medical fitness', 'medical fitness certificate', 'health certificate'],
        'DISABILITY_CERTIFICATE' => ['disability certificate', 'udid', 'udid card'],
        'VACCINATION_CERTIFICATE' => ['vaccination', 'vaccination certificate', 'vaccine certificate', 'covid certificate', 'covid vaccination'],
        'INSURANCE_CARD' => ['insurance', 'insurance card', 'health card', 'health insurance card', 'e card', 'ecard', 'mediclaim', 'mediclaim card'],
        // Exit
        'RESIGNATION_LETTER' => ['resignation', 'resignation letter', 'resignation mail'],
        'NO_DUES' => ['no dues', 'no due', 'nodues', 'no dues certificate', 'clearance form', 'exit clearance'],
        'EXIT_INTERVIEW' => ['exit interview', 'exit interview form', 'exit feedback'],
        'FNF_STATEMENT' => ['fnf', 'f and f', 'full and final', 'fnf statement', 'full and final settlement', 'settlement statement'],
        // Personal
        'PHOTOGRAPH' => ['photo', 'photograph', 'passport photo', 'passport size photo', 'passport size photograph', 'picture', 'pic', 'profile photo'],
        'BIRTH_CERTIFICATE' => ['birth certificate', 'dob certificate', 'date of birth certificate', 'dob proof'],
        'MARRIAGE_CERTIFICATE' => ['marriage certificate', 'marriage proof'],
        'NAME_CHANGE' => ['name change', 'name change proof', 'gazette', 'gazette notification', 'name affidavit'],
        'DEPENDANT_ID' => ['dependant id', 'dependent id', 'nominee id', 'dependant aadhaar', 'dependent aadhaar', 'spouse aadhaar', 'nominee aadhaar'],
        'OTHER' => ['other', 'others', 'misc', 'miscellaneous'],
    ];

    /** A type linked to one of these profile fields answers to that standard type's spellings, whatever its own code. */
    private const FIELD_CODES = ['statutory.aadhaar' => 'AADHAAR', 'statutory.pan' => 'PAN', 'statutory.uan' => 'UAN_CARD', 'passport' => 'PASSPORT', 'driving_licence' => 'DRIVING_LICENCE',
        'voter_id' => 'VOTER_ID', 'ration_card' => 'RATION_CARD', 'oci_card' => 'OCI_CARD', 'bank_account' => 'BANK_PROOF', 'address' => 'ADDRESS_PROOF'];

    /** Phrases that only name a broad kind of paper: they count only when nothing more specific is in the name. */
    private const WEAK = ['address', 'address proof', 'degree', 'degree certificate', 'certification', 'training', 'medical', 'insurance', 'contract', 'agreement', 'joining',
        'offer', 'appointment', 'experience', 'confirmation', 'promotion', 'resignation', 'graduation', 'masters', 'bachelor', 'bachelors', 'diploma', 'photo', 'picture', 'pic',
        'cheque', 'licence', 'license', 'bank details', 'passbook', 'other', 'others', 'misc', 'miscellaneous', 'increment', 'warning', 'pg', 'provisional'];

    /** Words that say nothing about what a file is ("Aadhaar front scan.jpg"). */
    private const NOISE = ['front', 'back', 'side', 'copy', 'scan', 'scanned', 'self', 'attested', 'page', 'pages', 'final', 'new', 'updated', 'latest', 'img', 'image', 'file',
        'doc', 'docs', 'document', 'documents', 'of', 'the', 'my', 'and', 'with', 'colour', 'color', 'original', 'part'];

    /** How much a phrase says: a type's own name or code, a usual spelling, or a type's name without its bracketed part. */
    private const OWN = 3;

    private const ALIAS = 2;

    private const DERIVED = 1;

    /** Codes of the document types issued letters are kept under (Letters::documentType()). */
    private const ISSUED_LETTER_PREFIX = 'LETTER_';

    /** @var array<string, list<array{type: int, rank: int}>> normalised phrase => the types it names, and how surely */
    private array $phrases = [];

    /** @var array<string, string> a phrase of several words written as one ("aadhaarcard") => the phrase */
    private array $compact = [];

    /** @var array<string, true> every word any phrase uses (for correcting misspellings) */
    private array $vocabulary = [];

    private int $longest = 1;

    /** @var array<int, DocumentType> */
    private array $types = [];

    /** @param  iterable<DocumentType>  $types  the active types files may be matched to */
    public function __construct(iterable $types)
    {
        foreach ($types as $type) {
            $this->types[(int) $type->id] = $type;
            // The types the Letters module keeps the letters PeopleOS issues under (LETTER_OFFER "Offer letter", …) answer
            // only when no other type does: an uploaded "Experience letter.pdf" is the previous employer's, not one issued here.
            $issued = str_starts_with(strtoupper((string) $type->code), self::ISSUED_LETTER_PREFIX);
            foreach (self::ownPhrases($type) as $phrase => $rank) {
                $this->add((string) $phrase, (int) $type->id, $issued ? self::DERIVED : $rank);
            }
            foreach (self::aliasesOf($type) as $phrase) {
                $this->add($phrase, (int) $type->id, self::ALIAS);
            }
        }
    }

    /**
     * The type a file is, read from its name. $ignore: words that are not about the document (the employee's code and
     * name). $folder: the name of the folder the file is in inside the employee's zip, tried when the file's own name says
     * nothing.
     *
     * @param  list<string>  $ignore
     * @return array{type: ?DocumentType, by: ?string, candidates: list<string>}
     */
    public function match(string $fileName, array $ignore = [], ?string $folder = null): array
    {
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $found = $this->matchText($stem, $ignore);
        if ($found['type'] === null && $found['candidates'] === [] && filled($folder)) {
            $byFolder = $this->matchText((string) $folder, $ignore);
            if ($byFolder['type'] !== null) {
                return ['type' => $byFolder['type'], 'by' => 'folder', 'candidates' => []];
            }
        }

        return $found;
    }

    /** @return array{type: ?DocumentType, by: ?string, candidates: list<string>} */
    private function matchText(string $text, array $ignore): array
    {
        $skip = [];
        foreach ($ignore as $word) {
            foreach (self::tokens((string) $word) as $token) {
                $skip[$token] = true;
            }
        }
        $words = [];
        foreach (self::tokens(self::withoutDates($text)) as $token) {
            if (isset($skip[$token])) {
                continue;
            }
            // A phrase written as one word ("aadhaarcard", "salaryslip") is read as its words.
            array_push($words, ...(! isset($this->phrases[$token]) && isset($this->compact[$token]) ? explode(' ', $this->compact[$token]) : [$token]));
        }
        if ($words === []) {
            return ['type' => null, 'by' => null, 'candidates' => []];
        }

        $found = $this->lookup($words);
        if ($found['type'] !== null || $found['candidates'] !== []) {
            return $found;
        }

        // Misspelt words ("pasport", "relieveing", "markseet") read as the nearest word a phrase uses.
        $corrected = array_map(fn (string $w) => $this->nearestWord($w) ?? $w, $words);
        if ($corrected !== $words) {
            $found = $this->lookup($corrected);
            if ($found['type'] !== null) {
                return ['type' => $found['type'], 'by' => 'fuzzy', 'candidates' => []];
            }

            return $found;
        }

        return ['type' => null, 'by' => null, 'candidates' => []];
    }

    /**
     * The longest phrase in the words decides (a specific one before a broad one); two types at the same length are a tie.
     *
     * @param  list<string>  $words
     * @return array{type: ?DocumentType, by: ?string, candidates: list<string>}
     */
    private function lookup(array $words): array
    {
        $whole = implode(' ', $words);
        $core = implode(' ', array_values(array_filter($words, fn (string $w) => ! in_array($w, self::NOISE, true))));
        // The whole name, or the name without its noise words, is a phrase: that decides.
        foreach (array_unique(array_filter([$whole, $core, $this->compact[str_replace(' ', '', $whole)] ?? null])) as $candidate) {
            if (isset($this->phrases[$candidate])) {
                return $this->resolve([$candidate]);
            }
        }

        $best = ['strong' => [0, []], 'weak' => [0, []]];
        $count = count($words);
        for ($n = min($this->longest, $count); $n >= 1; $n--) {
            for ($i = 0; $i + $n <= $count; $i++) {
                $phrase = implode(' ', array_slice($words, $i, $n));
                if (! isset($this->phrases[$phrase])) {
                    continue;
                }
                $tier = in_array($phrase, self::WEAK, true) ? 'weak' : 'strong';
                if ($n > $best[$tier][0]) {
                    $best[$tier] = [$n, [$phrase]];
                } elseif ($n === $best[$tier][0]) {
                    $best[$tier][1][] = $phrase;
                }
            }
        }
        $phrases = $best['strong'][1] !== [] ? $best['strong'][1] : $best['weak'][1];

        return $phrases === [] ? ['type' => null, 'by' => null, 'candidates' => []] : $this->resolve($phrases);
    }

    /**
     * @param  list<string>  $phrases  equally good phrases found
     * @return array{type: ?DocumentType, by: ?string, candidates: list<string>}
     */
    private function resolve(array $phrases): array
    {
        // A type's own name or code outranks the same words as another type's usual spelling, which outranks a type's name
        // without its bracketed part ("Relieving letter (issued)" is not what "relieving letter" usually means).
        $ranks = [];
        foreach ($phrases as $phrase) {
            foreach ($this->phrases[$phrase] as $entry) {
                $ranks[$entry['type']] = max($ranks[$entry['type']] ?? 0, $entry['rank']);
            }
        }
        $top = max($ranks);
        $types = array_keys(array_filter($ranks, fn (int $rank) => $rank === $top));
        if (count($types) === 1) {
            return ['type' => $this->types[$types[0]], 'by' => $top === self::ALIAS ? 'alias' : 'name', 'candidates' => []];
        }
        $names = array_map(fn (int $id) => (string) $this->types[$id]->name, $types);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return ['type' => null, 'by' => null, 'candidates' => $names];
    }

    private function nearestWord(string $word): ?string
    {
        $length = strlen($word);
        if ($length < 4 || isset($this->vocabulary[$word]) || ctype_digit($word)) {
            return null;
        }
        $allowed = $length >= 7 ? 2 : 1;
        $best = null;
        $bestDistance = $allowed + 1;
        $tie = false;
        foreach (array_keys($this->vocabulary) as $candidate) {
            if (abs(strlen($candidate) - $length) > $allowed || strlen($candidate) < 4) {
                continue;
            }
            $distance = levenshtein($word, $candidate);
            if ($distance < $bestDistance) {
                [$best, $bestDistance, $tie] = [$candidate, $distance, false];
            } elseif ($distance === $bestDistance && $candidate !== $best) {
                $tie = true;
            }
        }

        return $best !== null && ! $tie && $bestDistance <= $allowed ? $best : null;
    }

    private function add(string $phrase, int $typeId, int $rank): void
    {
        $phrase = implode(' ', self::tokens($phrase));
        if ($phrase === '') {
            return;
        }
        foreach ($this->phrases[$phrase] ?? [] as $i => $entry) {
            if ($entry['type'] === $typeId) {
                $this->phrases[$phrase][$i]['rank'] = max($entry['rank'], $rank);

                return;
            }
        }
        $this->phrases[$phrase][] = ['type' => $typeId, 'rank' => $rank];
        $words = explode(' ', $phrase);
        $this->longest = max($this->longest, count($words));
        if (count($words) > 1 && strlen($joined = implode('', $words)) >= 5) {
            $this->compact[$joined] ??= $phrase;
        }
        foreach ($words as $word) {
            $this->vocabulary[$word] = true;
        }
    }

    /**
     * A type's own phrases and how surely each names it: its name and its code as words (surely), its name without the
     * bracketed part and the bracketed part itself, unless it is an "e.g." list (less surely).
     *
     * @return array<string, int> phrase => rank
     */
    public static function ownPhrases(DocumentType $type): array
    {
        $name = (string) $type->name;
        $phrases = [];
        $put = function (string $text, int $rank) use (&$phrases): void {
            $phrase = implode(' ', self::tokens($text));
            if ($phrase !== '') {
                $phrases[$phrase] = max($phrases[$phrase] ?? 0, $rank);
            }
        };
        $put($name, self::OWN);
        $put(str_replace('_', ' ', (string) $type->code), self::OWN);
        $put((string) preg_replace('/\s*\([^)]*\)/', '', $name), self::DERIVED);
        if (preg_match_all('/\(([^)]*)\)/', $name, $m)) {
            foreach ($m[1] as $inside) {
                if (! preg_match('/^\s*e\.?\s?g\b/i', $inside) && mb_strlen(trim($inside)) >= 3) {
                    $put($inside, self::DERIVED);
                }
            }
        }

        return $phrases;
    }

    /** @return list<string> the usual spellings a type answers to (by its code, or the profile field it is linked to) */
    public static function aliasesOf(DocumentType $type): array
    {
        $codes = [strtoupper((string) $type->code)];
        $field = $type->getAttributes()['linked_field'] ?? null;
        if (is_string($field) && isset(self::FIELD_CODES[$field])) {
            $codes[] = self::FIELD_CODES[$field];
        }
        $aliases = [];
        foreach (array_unique($codes) as $code) {
            foreach (self::ALIASES[$code] ?? [] as $alias) {
                $aliases[] = $alias;
            }
        }

        return array_values(array_unique($aliases));
    }

    /**
     * A name as words: lower case, accents and punctuation dropped, "&" read as "and", single letters in a row joined
     * ("M.B.A" → "mba", "P A N" → "pan").
     *
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $text = mb_strtolower(Str::ascii(str_replace('&', ' and ', $text)));
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        $letters = '';
        foreach ($words as $word) {
            if (strlen($word) === 1 && ctype_alpha($word)) {
                $letters .= $word;

                continue;
            }
            $letters !== '' && $out[] = $letters;
            $letters = '';
            $out[] = $word;
        }
        $letters !== '' && $out[] = $letters;

        return $out;
    }

    /**
     * Dates written in a file name ("Passport valid till 2031-03-31", "Visa exp 15.08.2027"): YYYY-MM-DD, or DD-MM-YYYY
     * with -, /, . or _ between. A date after "exp", "expiry", "valid", "till", "upto" or "until" is the expiry date; one
     * after "issued", "issue", "dated" or "doi" the issue date; any other is the expiry date when it is still to come and
     * the issue date when it has passed.
     *
     * @return array{issued_on: ?string, expires_on: ?string}
     */
    public static function dates(string $fileName, ?Carbon $today = null): array
    {
        $today ??= today();
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $out = ['issued_on' => null, 'expires_on' => null];
        foreach (self::dateMatches($stem) as [$date, $before]) {
            $kind = match (true) {
                (bool) preg_match('/(exp|expiry|expires|expiring|valid|validity|till|upto|until)\W*$/i', $before) => 'expires_on',
                (bool) preg_match('/(issued|issue|dated|dt|doi)\W*$/i', $before) => 'issued_on',
                default => Carbon::parse($date)->gt($today) ? 'expires_on' : 'issued_on',
            };
            $out[$kind] ??= $date;
        }

        return $out;
    }

    private static function withoutDates(string $text): string
    {
        return (string) preg_replace(['/(?<!\d)\d{4}[-\/._]\d{1,2}[-\/._]\d{1,2}(?!\d)/', '/(?<!\d)\d{1,2}[-\/._]\d{1,2}[-\/._]\d{4}(?!\d)/'], ' ', $text);
    }

    /** @return list<array{0: string, 1: string}> each valid date (Y-m-d) with the text before it */
    private static function dateMatches(string $stem): array
    {
        $found = [];
        if (preg_match_all('/(?<!\d)(\d{4})[-\/._](\d{1,2})[-\/._](\d{1,2})(?!\d)/', $stem, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => [$text, $offset]) {
                [$y, $mo, $d] = [(int) $m[1][$i][0], (int) $m[2][$i][0], (int) $m[3][$i][0]];
                checkdate($mo, $d, $y) && $found[$offset] = [sprintf('%04d-%02d-%02d', $y, $mo, $d), substr($stem, max(0, $offset - 16), min(16, $offset))];
            }
        }
        if (preg_match_all('/(?<!\d)(\d{1,2})[-\/._](\d{1,2})[-\/._](\d{4})(?!\d)/', $stem, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => [$text, $offset]) {
                [$d, $mo, $y] = [(int) $m[1][$i][0], (int) $m[2][$i][0], (int) $m[3][$i][0]];
                checkdate($mo, $d, $y) && $found[$offset] = [sprintf('%04d-%02d-%02d', $y, $mo, $d), substr($stem, max(0, $offset - 16), min(16, $offset))];
            }
        }
        ksort($found);

        return array_values($found);
    }
}
