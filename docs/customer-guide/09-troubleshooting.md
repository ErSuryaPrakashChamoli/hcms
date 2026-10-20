# 9. Troubleshooting

> **In this chapter:** what to do when something does not work, written for non-technical users. Each problem lists
> what you see, what it usually means, what to check, how to fix it, when to involve your administrator and what to
> tell support. **Who should read it:** everyone.

> **Keep your account safe.** Never share your password, the six-digit code from your authenticator app, recovery
> codes, API keys or other people's personal data with anyone — including your HR team, your administrator or
> Markedge support. Nobody legitimate needs them. When you report a problem, describe it and share a screenshot with
> personal data hidden.

Messages shown in quotation marks below are the exact wording PeopleOS uses.

---

## 9.1 I cannot sign in

| What you see | What it usually means | What to do |
|---|---|---|
| "These credentials do not match our records." | The e-mail or password is wrong — or your account is suspended, not yet activated, or your organisation's access is suspended (PeopleOS shows the same message for all of these, so that nobody can probe accounts). | Check the e-mail address (your **work** e-mail) and Caps Lock. Use **Forgot password?** if unsure. |
| "Too many login attempts — Please try again in N seconds." | More than five attempts in a minute. | Wait for the time shown, then try once, carefully. |
| You are suddenly back on the sign-in page, or signing in seems to do nothing, **without any message** | PeopleOS signed you out for one of these reasons: you were inactive longer than your organisation allows; your password was changed or an administrator used **Account › Sign out everywhere**; your account is suspended or you have not accepted your invitation; or your organisation's access is suspended. PeopleOS records the reason but does not display it on the sign-in page today. | Sign in again. If you are sent straight back, ask your administrator to check your account status (**Admin › Setup › Users**). If nobody in your organisation can sign in, your administrator should contact Markedge. |
| A page asking for a six-digit code | Your account uses multi-factor sign-in. | Open your authenticator app and enter the current code. Lost your phone? See 9.3. |
| A page asking you to set up an authenticator app | Your organisation requires multi-factor sign-in and you have not set it up. | Follow the steps on screen (3.2 step 4). |
| A page asking you to verify your e-mail address | Your e-mail address has not been confirmed yet. | Open the verification e-mail and click the link; use the page's option to send it again if needed. |
| "This page is not available to you" right after signing in from an old link | The link points to something your role does not include. | Go to Home (`/admin`). |

**When your administrator is needed:** suspended accounts, invitations that expired, single sign-on problems, and
organisation-wide suspension.
**What to tell support:** your e-mail address, the exact message, the time, and whether you use single sign-on.

---

## 9.2 I forgot my password

1. On the sign-in page, click **Forgot password?**.
2. Enter your work e-mail and send the request.
3. PeopleOS always shows the same confirmation ("If your account doesn't exist, you will not receive the email."),
   whether or not the address exists — this protects accounts from being guessed.
4. Open the e-mail and follow the link within **60 minutes**. Choose a new password.

**If no e-mail arrives:** check spam/junk folders; wait a few minutes; make sure you typed your work address. After a
few attempts PeopleOS shows "Too many requests" — wait before trying again. If it still does not arrive, ask your
administrator to open your user record and choose **Account › Send password reset link**.

---

## 9.3 I lost my phone or my authenticator app

1. Use one of the **recovery codes** you saved when you set up the app.
2. Then go to **avatar › Account security** and set up the authenticator app again on your new phone.
3. No recovery codes? Ask your administrator to open your user record and choose **Account › Reset multi-factor
   authentication**, then set it up again when you next sign in.

**Never** send your recovery codes or authenticator codes to anyone.

---

## 9.4 My invitation link does not work

- Invitation links work **once** and expire after **72 hours** (the provider can change this period).
- Ask your administrator to open your user in **Admin › Setup › Users** and choose **Account › Send a new invitation**.
- If you already accepted it, sign in normally on the sign-in page.

---

## 9.5 A page or menu item is not visible

| Check | Why |
|---|---|
| Is it a personal page (My HR, My day, My career…)? | These need your user account to be linked to an employee record. Ask HR. |
| Do your roles include it? | The menu only shows what your roles allow (see [Chapter 6](06-role-specific-guides.md)). |
| Is the feature switched on? | Some features are switched on or off by your administrator (**Admin › Setup › All in Setup › Feature flags**), for example the AI assistants. |
| Is it in the "All in …" menu? | Less frequent modules are listed under **All in [area]** on the area bar. |
| Can search find it? | Press **Ctrl K** and type its name. |

**Administrator needed** if you should have access. Tell them the module name and why you need it.

---

## 9.6 "This page is not available to you" (access denied)

PeopleOS shows this page (error 403) when you open a page or record that your role or organisation scope does not
include. Nothing was shown and nothing was changed. Go back to Home, and ask your HR administrator if you think you
should have access. Access is decided by your permissions, not by the link — sharing a link does not share access.

## 9.7 "We could not find that" (error 404)

The page or record does not exist here: it may have been removed, or the link is out of date. Records from other
organisations are never shown, so a link from elsewhere can lead here. Use search (**Ctrl K**).

## 9.8 "Your session expired" (error 419)

The page was open for a long time and its security token is no longer valid. What you were submitting was **not**
saved. Go back, refresh the page and enter it again.

## 9.9 "Too many requests in a short time" (error 429)

More requests arrived than allowed for a short period. This protects everyone's data; nothing is wrong with your
account. Wait a minute and try again.

## 9.10 "Something went wrong on our side" (error 500)

PeopleOS could not complete the request because of an unexpected error; the error has been logged. Most changes are
saved in a single step, so a failed request usually leaves nothing half-saved. Try again in a moment. If it keeps
happening, tell your administrator what you were doing and when.

## 9.11 "PeopleOS is being updated" (error 503)

The service is briefly unavailable for maintenance. Your data is safe; try again in a few minutes.

---

## 9.12 I cannot save a form

| What you see | What to do |
|---|---|
| A red message under a field, for example "The Reason field is required." | Fields marked with a red asterisk (*) are required. Fill them in and save again. |
| A message about a value, for example a date before another date | Read the message next to the field; it says what is expected. |
| A message at the top of a form or a red pop-up after **Save** | The action was refused by a business rule (for example not enough leave balance, a record already locked, or you cannot approve your own request). The message names the rule. |
| You navigate away and PeopleOS warns about unsaved changes | Stay on the page and save, or confirm that you want to leave without saving. |
| Nothing happens after clicking **Save** | Wait a moment (some actions take a few seconds); check your internet connection; refresh the page — unsaved data is lost after a refresh. |

**Common business rules that stop a save** (examples from PeopleOS): you cannot approve or verify something you
requested or uploaded yourself; records in a final state (a finalised payroll run, a locked appraisal, a closed case)
cannot be edited; some changes need an effective date and do not overwrite history.

---

## 9.13 A record is not in the list

1. **Clear the search box** and the **filters** (the filter icon shows a number when filters are active).
2. **Check the lens or view** at the top of the list (for example "Mine", "My team").
3. **Check your organisation scope.** HR people and managers may only see certain companies or teams.
4. **Check the status.** Some lists hide closed or archived records by default; look for a status filter.
5. **Was it saved?** Look for it in **Insights › Audit › Change history** (if you have access) or ask the person who
   created it.

---

## 9.14 A report or dashboard is empty

- Check the report's filters and date range.
- Reports only include data you are allowed to see; a scoped user sees fewer rows than an administrator.
- Some analytics hide very small groups to protect privacy (for example survey results by department need a minimum
  number of respondents).
- New organisations have little data: figures fill in as people use PeopleOS.

---

## 9.15 A file upload failed

| Message | Meaning | What to do |
|---|---|---|
| "That file type is not accepted (pdf, jpg, jpeg, png, doc, docx)." | Only these types are accepted for documents. | Save or scan the file as PDF, JPG or PNG. |
| "The file is too large." | Documents are limited to 10 MB. | Compress the PDF or scan at a lower resolution. |
| "The file is empty." | The file has no content. | Check the file opens on your computer. |
| "The file content is not an accepted document type." | The file's content does not match its name (for example a renamed file). | Export the document again in a supported format. |

---

## 9.16 I did not receive a notification

1. Check the **bell** (top right) and **My work › Notifications** — in-app notifications appear immediately.
2. E-mails are sent shortly after the action; check spam/junk.
3. Check **avatar › Preferences**: you may have switched off optional notification types.
4. Administrators can check **Communication › Communication › Delivery log** to see whether a notification was sent, and use
   **Send again** if the recipient confirms they did not get it.
5. Only e-mail and in-app notifications are delivered today; SMS, WhatsApp and push appear as channel options but send
   nothing.

---

## 9.17 A workflow or request seems stuck

1. Open the request and look at **who has it now** (requests show the current owner and status).
2. The step may be waiting for someone: tasks appear in that person's **My work › Task inbox**.
3. Escalations and reminders depend on how your administrator configured the workflow.
4. Administrators can open **Workflows › Runs** to see each step of a workflow run.

**What to tell your administrator:** the request number or name, when it was submitted, and the last status shown.

---

## 9.18 An integration failed

For administrators:
- **Admin › Platform › Inbound events** shows events received from other systems and whether they were processed.
- **Admin › Platform › Webhooks** shows outgoing deliveries and failures; PeopleOS retries failed deliveries.
- **Admin › Platform › API keys**: check the key is active and has the right scopes.
- Attendance devices: check **People › Time & leave › Devices** and the punch imports.

**What to tell Markedge support:** the integration name, the time of the failure, and the event or delivery
identifier shown on screen — never the API key itself.

---

## 9.19 The data looks wrong

1. **Check the effective date.** Many changes apply from a date in the future or the past; PeopleOS keeps history
   instead of overwriting it.
2. **Check the change history.** **Insights › Audit › Change history** shows who changed what, when, the old and new
   value and the reason.
3. **Check the source.** Leave balances come from the leave ledger (**Transactions**); payroll figures come from the
   payroll run; attendance from punches and regularisations.
4. If a figure is calculated (balance, payroll, analytics), correct the input — not the result.

---

## 9.20 Payroll cannot be finalised

The most likely reason today: **the payroll relies on statutory rules that are not yet verified**. While enforcement is
on (the default), PeopleOS refuses to finalise such payroll, and it blocks payroll for a country for which it holds no
statutory rules. Open **People › Pay › Control room** to see blocking items. This is not something your organisation
can override; contact Markedge about the rule verification status. Other blocks (separation of duties, validation
errors, missing bank details) are explained on the run itself.

---

## 9.21 Before contacting support

- [ ] What were you trying to do? (module, action)
- [ ] The exact message (copy the text or take a screenshot with personal data hidden)
- [ ] Date and time it happened
- [ ] Your role and organisation (not your password)
- [ ] Whether it happens every time, and for other users too
- [ ] Browser and device (for example Chrome on Windows, phone or desktop)

Support channels and hours: **To be confirmed with the SaaS provider.** Inside your organisation, start with your
PeopleOS administrator or your HR team (employees can use **My HR › Ask HR**).
