# Dashboard enums — the literal accepted values

Answering the `city: "mecca"` → `VALIDATION` report. **This is our documentation bug:**
`NEXTJS-DASHBOARD-DEVIATIONS.md` said "currently 20 Saudi cities" and pointed you at
`Maps::CITIES` — a backend file you can't read. A count is not a contract. Here are the literal
values, copied from the source of truth (`app/Support/Dashboard/Maps.php`). This page is now the
reference for every closed enum in the wizard.

---

## 1. `city` — exactly these 20 slugs

```ts
export const CITIES = [
  "riyadh", "jeddah", "makkah", "madinah", "dammam",
  "khobar", "dhahran", "taif", "abha", "khamis_mushait",
  "tabuk", "buraydah", "hail", "jubail", "yanbu",
  "najran", "jazan", "alula", "baha", "hofuf",
] as const;
```

With display names (send the **slug**, never the label):

| slug | Arabic (what the public site renders) |
|---|---|
| `riyadh` | الرياض |
| `jeddah` | جدة |
| `makkah` | مكة المكرمة |
| `madinah` | المدينة المنورة |
| `dammam` | الدمام |
| `khobar` | الخبر |
| `dhahran` | الظهران |
| `taif` | الطائف |
| `abha` | أبها |
| `khamis_mushait` | خميس مشيط |
| `tabuk` | تبوك |
| `buraydah` | بريدة |
| `hail` | حائل |
| `jubail` | الجبيل |
| `yanbu` | ينبع |
| `najran` | نجران |
| `jazan` | جازان |
| `alula` | العلا |
| `baha` | الباحة |
| `hofuf` | الهفوف |

### Your 8 values — only 2 are wrong

| you send | verdict |
|---|---|
| `riyadh`, `jeddah`, `dammam`, `khobar`, `taif`, `abha` | ✅ exact match |
| `mecca` | ❌ → **`makkah`** |
| `medina` | ❌ → **`madinah`** |

That's the whole bug: two strings. Everything else already lines up.

### Why we did *not* just accept `mecca`/`medina` as aliases

It looks like the friendly fix, but it would hand you a worse bug. The slug→Arabic map is reversed
on read, and reverse lookup returns the **first** matching key. So:

```
POST city="mecca"  ->  stored "مكة المكرمة"  ->  GET /units/:id returns city="makkah"
```

You'd send `mecca` and get `makkah` back — your edit form would fail to match its own option and
silently blank the city. Aliases wouldn't save you the work; you'd still have to handle `makkah` on
read. One canonical value per city is the only shape that round-trips. (Cities are a code constant,
not a DB table — there's nothing to seed.)

---

## 2. `type` — exactly 3

```ts
export const UNIT_TYPES = ["apartment", "studio", "villa"] as const;
```

Lowercase slug. The `Studio` in your review screen must go over the wire as `studio`.

## 3. `amenities` — exactly these 8 keys

```ts
export const AMENITIES = [
  "wifi", "ac", "kitchen", "parking",
  "pool", "security", "self_checkin", "family_friendly",
] as const;
```

| key | Arabic |
|---|---|
| `wifi` | واي فاي |
| `ac` | تكييف |
| `kitchen` | مطبخ |
| `parking` | موقف سيارات |
| `pool` | مسبح |
| `security` | حراسة أمنية |
| `self_checkin` | تسجيل دخول ذاتي |
| `family_friendly` | مناسب للعائلات |

⚠️ Heads-up: this is the same trap as `city`. `amenities` is validated against exactly these 8 keys —
an unknown key fails with `fields["amenities.0"]`. Your review screen showed "0 selected", so you
likely haven't exercised this yet.

---

## Should you show all 20 cities?

Your call — we validate all 20 equally. Recommendation: **show all 20**. A partner in Tabuk can't
onboard if the dropdown only has 8, and there's no backend cost. Sort by the Arabic label for the
`ar` UI. If you'd rather launch with a shortlist, the 8 you have (with `mecca`→`makkah`,
`medina`→`madinah`) are the highest-volume ones and that's a reasonable Phase-1 cut.

## Need a city that isn't here?

Ask — it's a one-line addition plus a deploy. We won't add it silently, since each slug must map to
exactly one Arabic name the public site renders.

## Where these come from

`app/Support/Dashboard/Maps.php` (`CITIES`, `AMENITIES`) and `Unit::SUPPORTED_TYPES`. Validation is
generated directly from those constants (`in:` + `array_keys`), so this page and the validator can't
drift — if we add a city, this doc is the thing to update.
