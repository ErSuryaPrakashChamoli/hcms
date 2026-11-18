/*
 * PeopleOS public website behaviour (resources/views/website): the header's scrolled state, mega menus (hover with
 * intent on a mouse, click and keyboard everywhere, Escape and outside clicks close), the phone menu sheet, the
 * dismissible announcement and gentle reveals. Everything works without it: links stay links and nothing is hidden.
 */
const root = document.documentElement;

const header = document.querySelector('[data-ps-header]');
const scrim = document.querySelector('[data-ps-scrim]');
const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');

/* Header: solid once the page has scrolled */
const onScroll = () => header?.classList.toggle('is-scrolled', window.scrollY > 8);
onScroll();
window.addEventListener('scroll', onScroll, { passive: true });

/* Mega menus */
const items = [...document.querySelectorAll('[data-ps-menu]')];
let openItem = null;
let hoverTimer = null;
let openedAt = 0;

function panelOf(item) {
    return item.querySelector('[data-ps-panel]');
}

function triggerOf(item) {
    return item.querySelector('[data-ps-trigger]');
}

function close(item, { focus = false } = {}) {
    if (!item) return;
    triggerOf(item)?.setAttribute('aria-expanded', 'false');
    panelOf(item)?.classList.remove('is-open');
    if (openItem === item) openItem = null;
    if (!openItem) {
        header?.classList.remove('is-open');
        scrim?.classList.remove('is-on');
    }
    if (focus) triggerOf(item)?.focus();
}

function open(item) {
    if (openItem && openItem !== item) close(openItem);
    triggerOf(item)?.setAttribute('aria-expanded', 'true');
    panelOf(item)?.classList.add('is-open');
    if (openItem !== item) openedAt = Date.now();
    openItem = item;
    header?.classList.add('is-open');
    if (!item.classList.contains('has-compact')) scrim?.classList.add('is-on');
}

items.forEach((item) => {
    const trigger = triggerOf(item);
    const panel = panelOf(item);
    if (!trigger || !panel) return;

    trigger.addEventListener('click', (event) => {
        event.preventDefault();
        // A click that lands just after hover opened the panel keeps it open rather than toggling it shut.
        if (openItem === item && Date.now() - openedAt > 450) close(item);
        else open(item);
    });

    trigger.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            open(item);
            panel.querySelector('a')?.focus();
        }
    });

    panel.addEventListener('keydown', (event) => {
        const links = [...panel.querySelectorAll('a')];
        const at = links.indexOf(document.activeElement);
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const next = event.key === 'ArrowDown' ? Math.min(links.length - 1, at + 1) : Math.max(0, at - 1);
            links[next]?.focus();
        }
    });

    item.addEventListener('pointerenter', () => {
        if (!finePointer.matches) return;
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(() => open(item), openItem ? 0 : 90);
    });
    item.addEventListener('pointerleave', () => {
        if (!finePointer.matches) return;
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(() => close(item), 180);
    });

    item.addEventListener('focusout', (event) => {
        if (!item.contains(event.relatedTarget) && openItem === item) close(item);
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && openItem) close(openItem, { focus: true });
});
document.addEventListener('click', (event) => {
    if (openItem && !openItem.contains(event.target)) close(openItem);
});
scrim?.addEventListener('click', () => openItem && close(openItem));

/* Phone menu sheet */
const sheet = document.querySelector('[data-ps-sheet]');
const sheetOpeners = document.querySelectorAll('[data-ps-sheet-open]');
const sheetClosers = document.querySelectorAll('[data-ps-sheet-close]');

function setSheet(on) {
    if (!sheet) return;
    sheet.classList.toggle('is-open', on);
    sheet.setAttribute('aria-hidden', on ? 'false' : 'true');
    sheet.inert = !on;
    document.body.classList.toggle('is-locked', on);
    sheetOpeners.forEach((b) => b.setAttribute('aria-expanded', on ? 'true' : 'false'));
    if (on) sheet.querySelector('[data-ps-sheet-close]')?.focus();
    else sheetOpeners[0]?.focus();
}
if (sheet) sheet.inert = true;
sheetOpeners.forEach((b) => b.addEventListener('click', () => setSheet(true)));
sheetClosers.forEach((b) => b.addEventListener('click', () => setSheet(false)));
sheet?.addEventListener('keydown', (event) => event.key === 'Escape' && setSheet(false));
window.matchMedia('(min-width: 1081px)').addEventListener('change', (e) => e.matches && sheet?.classList.contains('is-open') && setSheet(false));

/* Announcement: dismissed per message, remembered on this device */
const announce = document.querySelector('[data-ps-announce]');
if (announce) {
    const key = `ps-announce:${announce.dataset.psAnnounce}`;
    try {
        if (localStorage.getItem(key) === '1') announce.hidden = true;
    } catch (e) {
        /* storage blocked: the bar simply stays */
    }
    announce.querySelector('[data-ps-announce-close]')?.addEventListener('click', () => {
        announce.hidden = true;
        try {
            localStorage.setItem(key, '1');
        } catch (e) {
            /* ignore */
        }
    });
}

/* Reveal on scroll */
// Only what is below the fold waits for its reveal; nothing is hidden before this runs (or if it never does).
const reveals = document.querySelectorAll('.ps-reveal');
if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    reveals.forEach((el) => el.getBoundingClientRect().top < window.innerHeight && el.classList.add('is-in'));
    root.classList.add('ps-js');
    const io = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-in');
                io.unobserve(entry.target);
            }
        });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    reveals.forEach((el) => io.observe(el));
} else {
    reveals.forEach((el) => el.classList.add('is-in'));
}

/* ------------------------------------------------------------------ Free tools (all worked out in the browser) */
const inr = (n) => '₹' + Math.round(n).toLocaleString('en-IN');
const localIso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const longDate = (iso) => {
    if (!iso) return '';
    const d = new Date(`${iso}T00:00:00`);
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' });
};

/* Letter generator: placeholders in the template become fields; the preview fills as people type. */
document.querySelectorAll('[data-ps-letter]').forEach((box) => {
    const template = box.querySelector('[data-ps-template]')?.content.textContent ?? '';
    const output = box.querySelector('[data-ps-output]');
    const inputs = [...box.querySelectorAll('[data-field]')];
    const values = () => Object.fromEntries(inputs.map((i) => [i.dataset.field, i.type === 'date' ? longDate(i.value) : i.value.trim()]));
    const plain = () => {
        const v = values();
        return template.replace(/\{([a-z][a-z0-9_]*)\}/g, (_, key) => v[key] || `[${key.replace(/_/g, ' ')}]`);
    };
    const render = () => {
        const v = values();
        output.replaceChildren();
        template.split(/(\{[a-z][a-z0-9_]*\})/g).forEach((part) => {
            const match = part.match(/^\{([a-z][a-z0-9_]*)\}$/);
            if (!match) {
                output.append(document.createTextNode(part));
                return;
            }
            const span = document.createElement('span');
            const filled = v[match[1]];
            span.className = filled ? 'ps-slot is-filled' : 'ps-slot';
            span.textContent = filled || match[1].replace(/_/g, ' ');
            output.append(span);
        });
    };
    const today = localIso(new Date());
    inputs.forEach((i) => {
        if (i.dataset.field === 'letter_date') i.value = today;
        i.addEventListener('input', render);
    });
    render();
    box.querySelector('[data-ps-copy]')?.addEventListener('click', async (e) => {
        const label = e.currentTarget.querySelector('span');
        try {
            await navigator.clipboard.writeText(plain());
            if (label) label.textContent = 'Copied';
        } catch (err) {
            if (label) label.textContent = 'Select the text to copy';
        }
        setTimeout(() => label && (label.textContent = 'Copy text'), 2000);
    });
    box.querySelector('[data-ps-print]')?.addEventListener('click', () => {
        document.body.classList.add('is-printing-letter');
        box.classList.add('is-printing');
        window.print();
        setTimeout(() => {
            document.body.classList.remove('is-printing-letter');
            box.classList.remove('is-printing');
        }, 500);
    });
    box.querySelector('[data-ps-reset]')?.addEventListener('click', () => {
        inputs.forEach((i) => (i.value = i.dataset.field === 'letter_date' ? today : ''));
        render();
    });
});

/* Calculators: gratuity, CTC to in-hand, leave encashment, notice period. */
document.querySelectorAll('[data-ps-calc]').forEach((box) => {
    const kind = box.dataset.psCalc;
    const ceiling = Number(box.dataset.ceiling || 0);
    const rules = (() => {
        try {
            return JSON.parse(box.dataset.rules || '{}');
        } catch {
            return {};
        }
    })();
    const field = (name) => box.querySelector(`[data-field="${name}"]`);
    const num = (name) => Math.max(0, Number(field(name)?.value || 0));
    const valueEl = box.querySelector('[data-ps-result-value]');
    const labelEl = box.querySelector('[data-ps-result-label]');
    const rowsEl = box.querySelector('[data-ps-result-rows]');
    const show = (label, value, rows) => {
        labelEl.textContent = label;
        valueEl.textContent = value;
        rowsEl.replaceChildren();
        rows.forEach(([k, v, strong]) => {
            const wrap = document.createElement('div');
            if (strong) wrap.className = 'is-strong';
            const dt = document.createElement('dt');
            dt.textContent = k;
            const dd = document.createElement('dd');
            dd.textContent = v;
            wrap.append(dt, dd);
            rowsEl.append(wrap);
        });
    };

    const calculators = {
        gratuity() {
            const salary = num('salary');
            const covered = field('covered')?.value !== '0';
            const fullYears = Math.floor(num('years'));
            const months = Math.min(11, Math.floor(num('months')));
            const years = covered ? fullYears + (months >= 6 ? 1 : 0) : fullYears;
            const divisor = covered ? 26 : 30;
            let amount = (15 / divisor) * salary * years;
            const capped = ceiling > 0 && amount > ceiling;
            if (capped) amount = ceiling;
            const eligible = fullYears >= 5;
            show(eligible ? 'Estimated gratuity' : 'Usually not yet eligible', inr(amount), [
                ['Formula', `15 ÷ ${divisor} × ${inr(salary)} × ${years} years`],
                ['Years counted', `${years} (${fullYears} years ${months} months of service)`],
                ...(capped ? [['Limited to the ceiling', inr(ceiling)]] : []),
                ['Eligibility', eligible ? 'Five years of continuous service met (check your policy)' : 'Needs five years of continuous service, except on death or disablement'],
            ]);
        },
        ctc() {
            // Statutory figures come from App\Domain\Website\Support\CalculatorRules (data-rules); these are fallbacks.
            const r = { pf_rate: 0.12, pf_ceiling: 25000, esi_employee: 0.0075, esi_employer: 0.0325, esi_ceiling: 21000, esi_ceiling_disability: 25000, esi_exempt_daily: 176, gratuity: 0.0481, ...rules };
            const pct = (rate) => `${+(rate * 100).toFixed(2)}%`;
            const monthly = num('ctc') / 12;
            const basic = (monthly * num('basic_pct')) / 100;
            const hra = (basic * num('hra_pct')) / 100;

            // PF is compulsory up to the wage ceiling; only an employee whose PF wages are above it at joining (an
            // "excluded employee") may stay out. The basic stands in for PF wages (basic + DA).
            const optOutBox = field('pf_opt_out');
            const canOptOut = basic > r.pf_ceiling;
            if (optOutBox) {
                optOutBox.disabled = !canOptOut;
                if (!canOptOut) optOutBox.checked = false;
            }
            const hint = box.querySelector('[data-ps-hint="pf_opt_out"]');
            if (hint) {
                hint.dataset.text ??= hint.textContent;
                hint.textContent = canOptOut ? hint.dataset.text : `Not available: PF wages of ${inr(basic)} are within the ${inr(r.pf_ceiling)} ceiling, so PF is compulsory.`;
            }
            const optedOut = canOptOut && Boolean(optOutBox?.checked);
            const capBox = field('pf_cap');
            if (capBox) capBox.disabled = optedOut;
            const pfWage = optedOut ? 0 : capBox?.checked ? Math.min(basic, r.pf_ceiling) : basic;
            const pf = Math.round(pfWage * r.pf_rate);
            const gratuity = field('gratuity_in_ctc')?.checked ? basic * r.gratuity : 0;

            // ESI is on gross pay and the employer's share comes out of CTC, so a covered gross is what is left ÷ 1.0325.
            // Coverage needs gross within the limit; the employee share is nil at a daily average wage within the floor.
            const beforeEsi = Math.max(0, monthly - pf - gratuity);
            const esiOn = Boolean(field('esi_on')?.checked);
            const esiLimit = field('esi_disability')?.checked ? r.esi_ceiling_disability : r.esi_ceiling;
            const covered = esiOn && beforeEsi > 0 && beforeEsi / (1 + r.esi_employer) <= esiLimit;
            const esiEmployer = covered ? Math.ceil((beforeEsi / (1 + r.esi_employer)) * r.esi_employer) : 0;
            const gross = beforeEsi - esiEmployer;
            const esiExempt = covered && gross / 30 <= r.esi_exempt_daily;
            const esiEmployee = covered && !esiExempt ? Math.ceil(gross * r.esi_employee) : 0;

            const special = Math.max(0, gross - basic - hra);
            const pt = num('pt');
            const inHand = Math.max(0, gross - pf - esiEmployee - pt);
            const rows = [
                ['Basic', inr(basic)],
                ['HRA', inr(hra)],
                ['Special allowance', inr(special)],
                ['Gross pay', inr(gross), true],
                optedOut ? ['PF', 'Opted out (not a member)'] : [`Employee PF (${pct(r.pf_rate)} of ${inr(pfWage)})`, '− ' + inr(pf)],
            ];
            if (!covered) rows.push(['ESI', esiOn ? `Not covered: gross above ${inr(esiLimit)}` : 'Establishment not covered']);
            else if (esiExempt) rows.push([`Employee ESI (nil up to ₹${r.esi_exempt_daily} a day)`, inr(0)]);
            else rows.push([`Employee ESI (${pct(r.esi_employee)})`, '− ' + inr(esiEmployee)]);
            rows.push(['Professional tax', '− ' + inr(pt)]);
            if (!optedOut) rows.push([`Employer PF in CTC (${pct(r.pf_rate)})`, inr(pf)]);
            if (covered) rows.push([`Employer ESI in CTC (${pct(r.esi_employer)})`, inr(esiEmployer)]);
            if (gratuity) rows.push(['Gratuity in CTC', inr(gratuity)]);
            rows.push(['Monthly CTC', inr(monthly), true]);
            show('Monthly take-home before income tax', inr(inHand), rows);
        },
        leave_encashment() {
            const divisor = Number(field('divisor')?.value || 30);
            const perDay = num('salary') / divisor;
            const days = num('days');
            show('Estimated encashment', inr(perDay * days), [
                ['Per-day pay', `${inr(num('salary'))} ÷ ${divisor} = ${inr(perDay)}`],
                ['Days encashed', String(days)],
            ]);
        },
        notice() {
            const resigned = field('resigned')?.value;
            if (!resigned) return show('Last working day', '—', []);
            const due = Math.max(0, num('notice_days') - num('waived'));
            const last = new Date(`${resigned}T00:00:00`);
            last.setDate(last.getDate() + due);
            const iso = localIso(last);
            const rows = [['Notice to serve', `${due} days after ${longDate(resigned)}`]];
            const leaving = field('leaving')?.value;
            if (leaving) {
                const short = Math.max(0, Math.round((last - new Date(`${leaving}T00:00:00`)) / 86400000));
                rows.push(['Days not served', String(short)]);
                rows.push(['Estimated recovery', inr((num('salary') / 30) * short), true]);
            }
            show('Last working day', longDate(iso), rows);
        },
    };
    const run = () => (calculators[kind] || calculators.gratuity)();
    box.querySelectorAll('[data-field]').forEach((i) => i.addEventListener('input', run));
    box.querySelectorAll('select[data-field], input[type="checkbox"]').forEach((i) => i.addEventListener('change', run));
    run();
});

/* ------------------------------------------------------------------ Contact form: mobile number verified by an SMS code */
document.querySelectorAll('[data-ps-enquiry][data-phone-mode="verify"]').forEach((form) => {
    const box = form.querySelector('[data-ps-phone]');
    if (!box) return;
    const $ = (sel) => box.querySelector(sel);
    const country = $('[data-ps-country]');
    const number = $('[data-ps-number]');
    const sendBtn = $('[data-ps-send]');
    const otp = $('[data-ps-otp]');
    const code = $('[data-ps-code]');
    const verifyBtn = $('[data-ps-verify]');
    const resendBtn = $('[data-ps-resend]');
    const timer = $('[data-ps-timer]');
    const masked = $('[data-ps-masked]');
    const tokenInput = $('[data-ps-token]');
    const verified = $('[data-ps-verified]');
    const status = $('[data-ps-phone-status]');
    const csrf = form.querySelector('input[name="_token"]')?.value ?? '';
    const initialHelp = status.textContent;
    let pending = '';
    let countdown = null;
    let lastWrong = '';

    const say = (text, tone = '') => {
        status.textContent = text;
        status.dataset.tone = tone;
    };
    const post = async (url, body) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });
        let data = {};
        try {
            data = await response.json();
        } catch (e) {
            /* no body */
        }
        if (!response.ok) {
            const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
            throw new Error(data.message && !data.errors ? data.message : first || data.message || 'Something went wrong. Please try again.');
        }
        return data;
    };
    const startTimer = (seconds) => {
        clearInterval(countdown);
        let left = seconds;
        resendBtn.disabled = true;
        timer.textContent = `in ${left}s`;
        countdown = setInterval(() => {
            left -= 1;
            if (left <= 0) {
                clearInterval(countdown);
                resendBtn.disabled = false;
                timer.textContent = '';
            } else {
                timer.textContent = `in ${left}s`;
            }
        }, 1000);
    };
    const reset = () => {
        clearInterval(countdown);
        pending = '';
        tokenInput.value = '';
        otp.hidden = true;
        verified.hidden = true;
        sendBtn.hidden = false;
        sendBtn.disabled = false;
        number.readOnly = false;
        box.classList.remove('is-verified');
        say(initialHelp);
    };
    const send = async () => {
        if (!number.value.trim()) {
            say('Enter your mobile number first.', 'error');
            number.focus();
            return;
        }
        sendBtn.disabled = true;
        resendBtn.disabled = true;
        say('Sending the code…');
        try {
            const data = await post(form.dataset.codeUrl, { country: country.value, phone: number.value });
            pending = data.token;
            lastWrong = '';
            masked.textContent = data.masked;
            otp.hidden = false;
            sendBtn.hidden = true;
            code.value = '';
            code.focus();
            startTimer(data.resend_in || 30);
            say(data.debug_code ? `Test server: no SMS provider is set up, so here is the code: ${data.debug_code}` : `Code sent to ${data.masked}. It is valid for ${Math.round((data.expires_in || 600) / 60)} minutes.`, data.debug_code ? 'info' : 'ok');
        } catch (error) {
            sendBtn.disabled = false;
            resendBtn.disabled = false;
            say(error.message, 'error');
        }
    };
    const verify = async () => {
        if (verifyBtn.disabled) return;
        if (!pending) return send();
        if (!code.value.trim()) {
            say('Enter the code from the text message.', 'error');
            code.focus();
            return;
        }
        if (code.value === lastWrong) {
            say('That code is not right. Check the text message and try again.', 'error');
            return;
        }
        verifyBtn.disabled = true;
        try {
            await post(form.dataset.verifyUrl, { token: pending, code: code.value });
            clearInterval(countdown);
            tokenInput.value = pending;
            otp.hidden = true;
            verified.hidden = false;
            number.readOnly = true;
            box.classList.add('is-verified');
            say('');
        } catch (error) {
            lastWrong = code.value;
            say(error.message, 'error');
            code.select();
        } finally {
            verifyBtn.disabled = false;
        }
    };

    sendBtn.addEventListener('click', send);
    resendBtn.addEventListener('click', send);
    verifyBtn.addEventListener('click', verify);
    code.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            verify();
        }
    });
    code.addEventListener('input', () => {
        code.value = code.value.replace(/\D/g, '');
        if (code.value.length >= Number(code.dataset.length || 6) && pending) verify();
    });
    $('[data-ps-change]').addEventListener('click', () => {
        reset();
        number.focus();
    });
    number.addEventListener('input', () => (tokenInput.value || pending) && reset());
    country.addEventListener('change', () => (tokenInput.value || pending) && reset());
    number.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !tokenInput.value) {
            e.preventDefault();
            send();
        }
    });
    form.addEventListener('submit', (e) => {
        if (!tokenInput.value) {
            e.preventDefault();
            say(pending ? 'Enter the code we sent to verify your mobile number.' : 'Verify your mobile number: press Send code.', 'error');
            (pending ? code : sendBtn).focus();
        }
    });
});

/* ------------------------------------------------------------------ Contact form: work e-mail only (the server checks too) */
document.querySelectorAll('[data-ps-enquiry][data-work-email]').forEach((form) => {
    let rule;
    try {
        rule = JSON.parse(form.dataset.workEmail);
    } catch (e) {
        return;
    }
    const input = form.querySelector('input[name="email"]');
    const error = form.querySelector('[data-ps-email-error]');
    if (!input || !error) return;
    const personal = (value) => {
        const at = value.lastIndexOf('@');
        if (at < 0) return false;
        const domain = value.slice(at + 1).trim().toLowerCase().replace(/\.$/, '');
        if (!domain) return false;
        if (rule.domains.includes(domain)) return true;
        return rule.families.includes(domain.split('.')[0]) && domain.split('.').length - 1 <= 2;
    };
    const check = () => {
        const bad = personal(input.value);
        error.hidden = !bad;
        if (bad) error.textContent = rule.message;
        input.setAttribute('aria-invalid', bad ? 'true' : 'false');
        return !bad;
    };
    input.addEventListener('blur', check);
    input.addEventListener('input', () => !error.hidden && check());
    form.addEventListener('submit', (e) => {
        if (!check()) {
            e.preventDefault();
            e.stopImmediatePropagation();
            input.focus();
        }
    }, true);
});
