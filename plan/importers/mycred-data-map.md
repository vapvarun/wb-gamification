# myCred 3.1.2 — Data-Storage Map for Lossless Import

Analyzed 2026-07-03 from a pristine wp.org download (source extracted alongside this
plugin for study — do NOT activate on customer sites). Basecamp epic: card 10061744564.

**Import rule:** READ their DB via SQL, WRITE only via our API/engine with provenance.
Constants that shape everything: `MYCRED_DEFAULT_TYPE_KEY='mycred_default'`,
`MYCRED_ENABLE_TOTAL_BALANCE=true`, `MYCRED_ENABLE_LOGGING=true` (mycred.php:151-161).

myCred = ONE ledger table + usermeta balances + CPTs (ranks/badges/coupons/buycred/
email notices) + options registry. The ledger is the primary migration source.

---

## 1. The ledger — `{prefix}myCRED_log` (only custom table)

Built in `mycred_install_log()` `includes/mycred-functions.php:2104-2134`:
id INT PK AI · ref VARCHAR(256) (event type key) · ref_id INT (context object id;
meaning depends on ref + data['ref_type']) · user_id INT · creds DECIMAL(22|32,d)
SIGNED (negative = deduction; precision follows the point type's decimals — read as
STRING, never float) · ctype VARCHAR(64) (point-type key = multi-currency
discriminator) · time BIGINT unix ts (site-LOCAL via current_time('timestamp') —
normalize to UTC on import, keep raw in provenance) · entry LONGTEXT (**template**
with %plural%/%display_name%/... placeholders, NOT rendered text) · data LONGTEXT
(heterogeneous: empty | scalar | numeric | **PHP-serialize()d** array — NOT JSON;
unserialize with allowed_classes=false; common keys ref_type/txn_id/payer_id/tid/to).
Indexes: single-column on ref, ref_id, user_id, ctype, time (no composites).

**Table-name variants (detect first!):** `{prefix}myCRED_log` (mixed case — case
sensitivity matters on Linux), lowercase `mycred_log` fallback probe
(functions.php:194), multisite central = base_prefix, per-site secondary = lowercase,
`MYCRED_LOG_TABLE` constant override (functions.php:201-202).

**`ref` taxonomy** (open set — VARCHAR, third-party add-ons register arbitrary refs):
core: registration, logging_in, site_visit, link_click, watching_video, anniversary,
signup_referral, visitor_referral, view_content(_author), comment/new_comment/
approved_comment/unapproved_comment/spam_comment, publishing_content, post, page,
deleted_content/comment...; BuddyPress/bbPress hook refs (new_profile_update,
new_friendship, joining_group, new_forum_topic, ...); add-ons: transfer, manual
(admin adjust), coupon, sell_content/buy_content, buy_creds_with_{gateway},
rank_promotion, payout (banking), cashcred_withdrawal, import, reset.
**Never treat the list as closed** — see completeness guard §8.

## 2. User data (usermeta)

Multisite caveat: `mycred_get_meta_key()` appends `_{blog_id}` on non-central blogs
(functions.php:2868-2888). Canonical enumeration: uninstaller
`includes/mycred-install.php:327-360`.

**Balances per point type `{type}`:**
- `{type}` (e.g. `mycred_default`) — **current balance (live)**
- `{type}_total` — lifetime accumulated
- `mycred_ref_counts-{type}` / `mycred_ref_sums-{type}` — per-ref aggregate CACHES (skip)
- `{type}_comp` — competition accumulator

**Rank:** `mycred_rank` = current rank POST id (default type); non-default types append
the type key (`mycred_rank{type}`); multisite appends blog id.

**Badges (per badge post id {pid}):**
- `mycred_badge{pid}` = **level index reached** (0-based int)
- `mycred_badge{pid}_issued_on` = unix ts awarded
- `mycred_badge_ids` = serialized `[badge_id => open_badge_flag]` of all held badges

**Transfers:** `mycred_transactions(_{type})` history arrays — REDUNDANT with ledger
(limits are computed live from ledger) → skip, ledger is truth. `mycred-last-transfer`
rate-limit ts → skip.

**Skip-list (caches/anti-abuse state, derivable from ledger):** mycred-last-send,
mycred-last-linkclick, mycred_comment_limit_post_%/day_%, mycred-last-clear-stats,
buycred_pending_payments (transient — myCred itself deletes on upgrade),
mycred_buycred_rates_{type}, mycred_banking_rate_{type},
mycred_sell_content_share_{type}, mycred_epp_% / mycred_payments_% (sell-content
records — report count), mycred_affiliate_link, mycred_email_unsubscriptions.

## 3. Post types + postmeta

- **`mycred_rank`**: meta `mycred_rank_min` / `mycred_rank_max` (point thresholds),
  `ctype` (currency this ladder belongs to), `mycred_rank_users` (cache),
  `mycred_rank_logo`/`_has_logo`. Order = min DESC. Rank base (balance vs total) lives
  in the point-type core option, not postmeta.
- **`mycred_badge`** (+ taxonomy `mycred_badge`): meta **`badge_prefs`** = master
  levels array — per level: image, label, compare AND|OR,
  `requires[] = {type: ctype, reference: ref, amount, by: count|sum}` (maps DIRECTLY
  onto ledger conditions!), `reward {type, amount, log}`. Legacy `badge_requirements`
  (pre-2.0) may exist un-migrated → read as fallback
  (mycred-badge-functions.php:142-208). Also: manual_badge, open_badge, main_image,
  level_image{n}, congratulation_msg, mycred_badge_align, total-users-with-badge (cache).
- **`buycred_payment`** (pending purchases): title=txn id, author=buyer; meta from/to
  (NOTE: swapped in code — preserve raw), amount, cost, currency, point_type, gateway.
  Transient workflow state → skip; completed purchases are ledger rows.
- **`mycred_coupon`**: title = coupon CODE; meta reward (type+amount), expires, min,
  max (global uses), user_limit, message templates. Redemptions = ledger
  ref='coupon', ref_id=coupon post, data=code.
- **`mycred_email_notice`**: body + meta mycred_email_settings/_ctype/_instance/
  _styling/_last_run.

## 4. Options / registry

- **`mycred_types`** — the multi-currency REGISTRY: `[type_key => label]`. Drive the
  whole import off this + `SELECT DISTINCT ctype FROM log`.
- `mycred_pref_core` (default type settings: format/decimals, name singular/plural,
  before/after, caps, exclude, frequency, delete_user + merged add-on sub-arrays);
  `mycred_pref_core_{type}` per extra currency.
- `mycred_pref_hooks(_{type})` — earning rules config (installed/active/hook_prefs
  amounts+limits+log templates).
- Add-on/global: mycred_pref_addons, mycred_pref_bank, mycred_pref_remote,
  mycred_pref_cashcreds, mycred_pref_transfer, woocommerce_mycred_settings,
  mycred_network, widget instances, mycred_version(_db), mycred_key (secret — never
  copy), mycred_setup_completed; caches mycred-cache-*.

## 5. Their APIs (references)

PHP: mycred_get_users_balance (:3286), mycred_get_users_total_balance (:3310),
mycred_add (:3430), mycred_subtract (:3450), mycred_get_users_rank,
mycred_get_badge_levels, mycred_get_types (:2451).
REST (admin-auth internal, shape reference): `mycred-dashboard/v1` —
/points/award, /points/deduct, /users/{id}/history, /award-badge-rank...;
`open-badge` namespace for assertions.
**Their importers as mapping templates** (`includes/importers/`):
mycred-balances.php (CSV user,balance → ref='import'), mycred-log-entries.php,
**mycred-cubepoints.php:240-309 — the canonical foreign-log→ledger translation
pattern to copy for OUR ref→action mapping.**

## 6. Mapping → wb-gamification (write via OUR API only)

| Source | Our concept | Write path | Idempotency key |
|---|---|---|---|
| `mycred_types` entries | Point types | `POST /point-types` | source type key |
| `myCRED_log` rows | Ledger events (PRIMARY migration) | `POST /events` import-mode: action=mapped ref, amount=creds, point_type=ctype, occurred_at=time(UTC-normalized), provenance={source:'mycred', log_id, ref, ref_id, raw_entry, raw_data} | log id |
| `{type}` balance meta | Reconciliation target | Reconcile ledger-replay result against it; report drift. (Alt fast mode: single opening-balance award) | user+type |
| `{type}_total` | Check value | provenance check only | — |
| `mycred_rank` CPT (min/max, ctype) | Levels | `POST /levels` per ladder per currency | rank post ID |
| user `mycred_rank` meta | Current level | derive from thresholds post-import; explicit set fallback | user+type |
| `mycred_badge` + badge_prefs levels | Badges (tiered!) | `POST /badges` — level tiers; requires[] maps to our condition config where possible, else config report | badge post ID |
| `mycred_badge{pid}`(+issued_on) | Badge awards | `POST /badges/{id}/award` backdated to issued_on, level=meta value | user+badge |
| transfer rows | Paired debit+credit events | 2× /events with counterparty in metadata | log id |
| coupon redemptions / buyCred completed / cashcred / banking payouts | Ledger events | /events with gateway/txn provenance | log id |

### GAPs (skip-with-report)
1. **Hook configs** (`mycred_pref_hooks*`) = earning RULES → manual re-authoring in our
   Settings; export a JSON reference report for the owner. History already in ledger.
2. **Coupon definitions** → we HAVE a redemption store; map coupon → redemption item
   where semantics fit (code+expiry+limits), else CSV report. Redemption history is
   ledger events either way.
3. **buyCred pending + gateway configs, cashCred payout configs, banking rates,
   transfer limits, email-notice templates** → operational config, out of scope,
   documented in report. (Email notices optionally importable as content later.)
4. **Caches/rate-limit counters** → skip by design (derivable; importing risks staleness).
5. **mycred_key + widget instances** → never copy (secret / UI state).

## 7. Scale + parsing cautions

- Ledger is the 1M+ row table (every BP/bbPress interaction writes a row). Page by
  id-cursor: `WHERE id > :cursor ORDER BY id ASC LIMIT 5000` — never OFFSET.
- Parse `data` with maybe_unserialize + allowed_classes=false; NEVER eval; keep raw
  blob in provenance whether or not parsing succeeds.
- `entry` is a placeholder template — store raw; render lazily if we surface it.
- `time` is site-local unix ts → normalize to UTC using site GMT offset.
- `creds` read as string/decimal per-type precision, not float.
- Detect actual table name (case variants + MYCRED_LOG_TABLE) before anything.

## 8. Completeness guard (run before declaring lossless)

1. `SELECT DISTINCT ref, ctype FROM {log}` → any ref not in our mapping table = new
   mapping row or explicit skip entry.
2. `SELECT DISTINCT meta_key FROM usermeta WHERE meta_key LIKE 'mycred%' OR meta_key
   LIKE '%_total'` → any key beyond §2 = investigate.
3. `SELECT DISTINCT meta_key FROM postmeta JOIN posts ... WHERE post_type IN
   ('mycred_rank','mycred_badge','buycred_payment','mycred_coupon',
   'mycred_email_notice')` → any key beyond §3 = investigate.
Any unmapped key = potential data loss = dry-run report line, never silent.
