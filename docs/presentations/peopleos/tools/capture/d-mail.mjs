// Reads the e-mails that the training server wrote to its log (MAIL_MAILER=log on a disposable instance).
// Links are returned to the capture scripts only; they are never printed or written into a screenshot.
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const LOG = process.env.TRAINING_MAIL_LOG
    ?? `/tmp/claude-1000/-home-administrator-Documents-hrms-hcms/e6fd4e9b-b051-4321-b3de-d1829a412023/scratchpad/server-${new URL(process.env.TRAINING_BASE_URL ?? 'http://127.0.0.1:8098').port}.log`;

// The log transport writes URLs unencoded: only undo soft line breaks and encoded '=' (never decode '=XX' blindly).
const qp = (s) => s.replace(/=\r?\n/g, '').replace(/=3D/g, '=');

/** All logged messages, oldest first: { to, subject, text, html }. */
export function messages() {
    const raw = readFileSync(LOG, 'utf8');
    const out = [];
    for (const block of raw.split(/local\.DEBUG: From: /).slice(1)) {
        const to = (block.match(/^To: (.+)$/m) ?? [])[1]?.trim();
        const subject = (block.match(/^Subject: (.+)$/m) ?? [])[1]?.trim();
        const boundary = (block.match(/boundary=([^\s;]+)/) ?? [])[1];
        let text = '', html = '';
        if (boundary) {
            for (const part of block.split(`--${boundary}`)) {
                const body = qp(part.split(/\r?\n\r?\n/).slice(1).join('\n\n'));
                if (/Content-Type: text\/plain/.test(part)) text = body;
                if (/Content-Type: text\/html/.test(part)) html = body.split(/\n\[\d{4}-\d\d-\d\d /)[0];
            }
        }
        out.push({ to, subject, text, html });
    }
    return out;
}

/** The latest link matching `pattern` in a message to `to`. */
export function latestLink(to, pattern) {
    const m = messages().filter((x) => x.to === to && pattern.test(x.text)).pop();
    if (!m) return null;
    return m.text.match(new RegExp(`https?://[^\\s\\])]*${pattern.source}[^\\s\\])]*`))?.[0] ?? null;
}

/** Write the latest message to `to` as an HTML file with every link removed (for a screenshot of the e-mail). */
export function redactedEmailFile(to, file) {
    const m = messages().filter((x) => x.to === to).pop();
    if (!m) return null;
    const html = m.html
        .replace(/href="[^"]*"/g, 'href="#"')
        .replace(/https?:\/\/[^\s<"]+\/(invitation|password-reset)[^\s<"]*/g, '[link removed for the screenshot]');
    const header = `<div style="font:14px system-ui;padding:12px 16px;background:#f3f4f6;border-bottom:1px solid #d1d5db">`
        + `<div><b>To:</b> ${m.to}</div><div><b>Subject:</b> ${m.subject}</div>`
        + `<div style="color:#6b7280;margin-top:4px">Rendered from the outgoing message written to the training server's mail log. Links removed.</div></div>`;
    mkdirSync(dirname(file), { recursive: true });
    writeFileSync(file, html.replace(/<body([^>]*)>/, `<body$1>${header}`));
    return m;
}
