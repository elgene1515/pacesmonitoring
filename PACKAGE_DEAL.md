# PACES Monitoring System — Package Deal

A system for tracking students and their PACEs (Accelerated Christian
Education workbooks) per subject, school year and quarter — with per-teacher
accounts and a cross-branch admin view. Offered as two package options below.

## Option A — SaaS (Subscription) — Recommended

The client never owns the code; they pay per year for hosting, updates and
support. Billed per school branch covered (an "admin" account can see and
manage every branch registered under the subscription).

| Tier | Branches covered | Price |
|------|-------------------|-------|
| Starter | 1 branch | ₱15,000 / year |
| Growth | up to 2 branches | ₱25,000 / year |
| Multi-campus | up to 5 branches | ₱50,000 / year |

**Included:** hosting, database backups, bug fixes, feature updates, email/chat
support, account recovery.
**Not included:** source code, self-hosting rights, custom feature development
(quoted separately).

### Founding-client offer (this client only)

| Tier | Branches covered | List price | Founding price |
|------|-------------------|------------|-----------------|
| Growth | up to 2 branches | ₱25,000 / year | **₱20,000 / year** (₱10,000 per branch) |

A ₱5,000/year (20%) discount off the Growth tier, as their first client.
Attach conditions so it doesn't just become a quiet permanent discount:
- Written as a **founding-client / early-adopter rate**, not a published
  price — don't list it anywhere the next client could point to.
- Lock it to a **minimum 2-year subscription term** (or at least require
  annual, not monthly, billing) so the discount buys you a committed
  relationship, not just a cheaper first year they can walk away from.
- Consider sunsetting it explicitly — e.g. "founding rate guaranteed for the
  first 2 years, list price after" — rather than an open-ended discount.

## Option B — System Buyout (Outright Purchase)

One-time payment, full handover of the source code and database, self-hosted
by the client from that point on.

| Item | Price |
|------|-------|
| Full system buyout (code + DB, perpetual license) | ₱50,000 – ₱80,000 (one-time) |

**Included:** full source code, database schema/export, a one-time setup/
deployment walkthrough.
**Not included:** hosting, future updates, bug fixes, or support — all billed
separately if the client wants them after handover (suggested: an optional
maintenance retainer, e.g. ₱8,000–12,000/year).

## Side-by-side

| | SaaS | Buyout |
|---|---|---|
| Upfront cost | ₱0 | ₱50,000–80,000 |
| Recurring cost | ₱15k–50k/year | ₱0 (unless maintenance retainer taken) |
| Hosting | Included | Client's responsibility |
| Updates/support | Included | Not included |
| Source code ownership | No | Yes |
| Adding a branch later | Upgrade tier | Build/maintain it yourself |

## Pricing notes (for internal use — not for the client)

**The SaaS figures look fair for this market and this system's scope.** It's
a multi-tenant Laravel + Vue app with real CRUD depth (school years, grade
levels/subjects, students, PACEs, PACE assignments, a projections view) plus
a separate admin rollup across teachers — more than a toy script, but still a
single-purpose vertical tool, not a full SIS. ₱15k/year for one branch
(~₱1,250/month) is modest for the time it saves a school doing this by hand
or in spreadsheets, and scales sensibly with branch count.

**The buyout price range needs a second look before you quote it.** At
₱50,000–80,000 flat regardless of branch count, it doesn't scale with the
same logic as the SaaS tiers — and that undercuts your own subscription
pitch:

- For a **5-branch** client, the ₱50k low end of the buyout is *exactly one
  year* of the Multi-campus SaaS tier. They'd be buying permanent ownership,
  code and all, for the price of a single year's rent. There's no reason
  for a multi-branch client to ever subscribe if that's on the table.
- For a **1–2 branch** client the math holds up better (buyout ≈ 2–5 years
  of subscription), which is closer to a normal "pay more to own it outright"
  premium.

Since your actual goal is to push this specific client toward subscription,
not sell the buyout, consider one of:
1. **Scale the buyout price with branch count** the same way SaaS does (e.g.
   ₱80k for 1 branch, ₱130k for up to 2, ₱220k for up to 5) so it's never
   cheaper than several years of the matching SaaS tier.
2. **Cap what buyout includes** — e.g. buyout only ever covers a single
   branch's deployment; multi-branch use still requires a subscription or a
   per-branch license add-on.
3. **Lead with SaaS in the conversation** and only quote the buyout number if
   the client pushes, pricing it at the high end (₱80k) with the maintenance
   retainer mentioned up front, so the "no support after handover" gap is
   visible before they compare it to a subscription price.

No currency conversions needed — this is quoted only in PHP since it is
your Philippine market.

## Closing this specific deal

Client profile: private school, 2 branches, ~10 teachers, ~100 students,
₱30,000/year tuition per student → roughly **₱3,000,000/year in tuition
revenue**.

At the founding-client price, the subscription is a non-issue financially:

- ₱20,000/year is **~0.67% of their annual tuition revenue**.
- That's about **₱200/student/year**, or **₱1,667/month** total for the
  whole school across both branches.
- Framed as cost per teacher using it, it's **₱2,000/teacher/year**.

Lead with that framing, not the discount — a school already clears this cost
easily, so the conversation is about value (replacing manual/spreadsheet PACE
tracking across 2 branches with one system an admin can see end-to-end), not
affordability.

Since the goal is to **secure them either way** (subscribe or buy), but
subscription is preferred:
1. Open with the founding-client SaaS price (₱20,000/year, 2-year term) as
   the primary offer — cheap relative to their revenue, and it keeps them on
   a relationship where you still get paid for support/updates.
2. If they push for the buyout instead, quote the **high end (₱80,000)**,
   not 50k — at 20k/year founding price, ₱80k is only 4 years of
   subscription, so it's still a fair exit price for you, not a giveaway.
3. Either way, get a signed term (subscription: 2-year lock at founding rate;
   buyout: no free support after handover, maintenance retainer offered
   separately) — the discount is justified by securing a committed first
   client and reference, not by giving up negotiating room later.



CLAUDE INSIGHTS
Insights on PACKAGE_DEAL.md against the current system

The value pitch is stronger than the document assumes. The pricing notes describe "real CRUD depth plus a projections view". Since then the system gained a full analytics layer: progress rings, pass and fail and retake tracking, per-quarter and per-subject breakdowns, top performers, hardest PACEs, and an admin dashboard that filters by teacher, year, grade level and subject. That is now closer to a lightweight school intelligence tool than a tracker, so the ₱15k to ₱50k per year tiers are, if anything, conservative. The document's own note that the SaaS prices "look fair" still holds, with more room to defend them.

Lead the demo with the admin Home. For a two-branch client with an admin who wants to see both branches end to end, the admin dashboard filters and the Hardest PACEs and Fails and retakes views are the clearest demonstration of what a spreadsheet cannot do. The "Closing this specific deal" framing should reference these by name rather than generic "one system an admin can see".

Retake tracking is a selling point worth adding. The deal document never mentions retakes. Schools that run PACEs care about repeat attempts because they drive cost and scheduling. The retake flag, retake rate per PACE, and the retake rings give the client a reason the system is specific to their workflow, not a generic gradebook.

The buyout gap has widened. The notes already warn that ₱50k to ₱80k undercuts the subscription for multi-branch clients. With the added analytics the replacement cost of the codebase is higher, so option 1 in the notes, scaling the buyout with branch count, is now the more defensible choice. If you quote a buyout at all, quote the ₱80k ceiling or above, with the maintenance retainer stated up front.

"Included: feature updates" now has concrete evidence. The subscription pitch promises updates. The scale of today's changes, delivered without data loss, is a good illustration of what a subscriber gets and what a buyout client would not.

One caution for the branch model. The document bills per branch and assumes an admin sees "every branch registered under the subscription". The system itself has no branch concept. Branches are represented by teachers under one admin. That works for this client, but the proposal text should avoid implying a formal branch feature, or you should plan a small branch or campus grouping if a larger multi-campus client appears.

Operational note for the quote. Any hosted deployment needs the new migration applied. That is a routine step, but it belongs in your internal rollout checklist for SaaS clients.