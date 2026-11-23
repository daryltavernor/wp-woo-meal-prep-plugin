# Food-Safety Prep Records — Proposal for an add-on

> **Status:** Phases 1 and 2 BUILT in v1.30.0 (23 Nov 2026) as `src/FoodSafety/` — Cook & Pack Record, Prep Records, Records Archive, Food Safety Settings, per-ingredient Food safety box, batch codes on the pick list, plus configurable sample readings for demonstration (specimen footer on prints, flagged rows in the archive). See the README section "Food-safety prep records". The text below is the original proposal.
> **Ask from the client:** a printable record of how each batch was prepared — how hot the chicken was cooked, at what time, when and where it was wrapped — produced alongside the existing Prep Sheet.
> **Recommendation:** start with a **printed "Cook & Pack Record"** generated from the data the Prep Sheet already has, with the measurements written in by hand. Add digital entry and an archive only if the client (or their Environmental Health Officer) asks for it.

---

## 1. What this is in food-safety terms

A UK kitchen preparing meals for sale must run a documented food-safety management system based on HACCP principles (Regulation (EC) 852/2004 as retained in UK law; Food Safety and Hygiene (England) Regulations 2013). Nearly every small caterer does this with the Food Standards Agency's **Safer Food, Better Business (SFBB)** pack: a set of "safe methods" plus a **daily diary** of checks. On inspection the EHO wants evidence that the critical points were checked and that a batch can be traced.

The client's question maps onto four of those records:

| Record | What is written down | Typical limit (to confirm against their SFBB pack / EHO) |
|---|---|---|
| **Cooking** | Core temperature from a probe, time, who checked | 75 °C core (or an equivalent time/temperature such as 70 °C for 2 minutes) |
| **Cooling** | Time out of the oven, time into the fridge, temperature when refrigerated | Into chilled storage within 90 minutes of cooking |
| **Packing / labelling** | Time packed, batch reference, use-by applied, allergen label checked | Use-by from the component's shelf life |
| **Chilled storage & dispatch** | Fridge/freezer temperatures AM and PM; temperature of delivery boxes when they leave | Fridge 5 °C target, 8 °C legal maximum in England; dispatch ≤ 8 °C |

Supporting checks that live on the same daily page in an SFBB diary: probe calibration (weekly, in iced water and boiling water), opening and closing checks, cleaning sign-off, and a corrective-action note whenever a reading is out of limit.

The limits above should be **settings, not hard-coded**: they vary slightly by nation (reheating is 82 °C in Scotland) and by what the client's own HACCP plan says.

---

## 2. Phase 1 — the simple version: a printed Cook & Pack Record

### 2.1 What it is

A new section on **Meal Prep → Prep Sheet** (or a separate "Cook & Pack Record" button next to Print / Download PDF) for the chosen fulfilment date. It pre-fills one row per component that has to be cooked that day, using the same ingredient totals the Prep Sheet already works out from `fn_prep_cache`, and leaves blanks for everything that is a measurement. Staff print it with the morning prep list, fill it in at the pass with the probe, sign it, and file it — exactly how an SFBB diary is kept today, but with the structure, quantities and batch codes done for them.

No tablet in the kitchen, no new database table, no behaviour change to ordering, labels or the existing Prep Sheet sections.

### 2.2 What a page looks like

```
COOK & PACK RECORD — Saturday 28 November 2026           Sheet 1 of 1   Signed off: ________

Fridge/freezer checks   Walk-in fridge  AM ____ °C  PM ____ °C   |  Freezer 1  AM ____ °C  PM ____ °C
Probe calibration (weekly)  Iced water ____ °C   Boiling ____ °C   Date ______  Initials ____

Batch        Component          Method    Portions  Target   Time in  Time out  Probe 1  Probe 2  Cooled ≤8°C by  Fridge  Packed  Use-by   Init.
261128-CHK-1 Chicken breast     Oven      64        75 °C    ____     ____      ____ °C  ____ °C  ____            ____    ____    01/12    ____
261128-BEF-1 Beef steak         Grill     22        75 °C    ____     ____      ____ °C  ____ °C  ____            ____    ____    01/12    ____
261128-SWP-1 Sweet potatoes     Oven      48        75 °C    ____     ____      ____ °C  ____ °C  ____            ____    ____    01/12    ____
261128-BRC-1 Broccoli           Steam     40        75 °C    ____     ____      ____ °C  ____ °C  ____            ____    ____    01/12    ____
—            Sweet treats (Dream Dolls)  No cook — ready to eat: supplier use-by checked ____  Initials ____

Out-of-limit readings and what was done: ______________________________________________________________
```

Rows come from the day's **Ingredient totals** (proteins, carbs, greens, set meals, sweets). Anything flagged *no-cook* on the ingredient prints a single "supplier use-by checked" line instead of temperature blanks. Add-ons that are cooked (boiled eggs, sauces) can be included the same way.

### 2.3 Batch codes

Each cooked component gets a code per day: `YYMMDD-<short code>-<n>` (for example `261128-CHK-1`; `-2` if a second batch of the same component is cooked). The code prints on the record and, optionally, next to each meal's components on the **per-order pick list**. That is the traceability link an EHO asks for in an incident — "which chicken batch went into order 23581?" — answered from two pieces of paper.

### 2.4 Data to add per ingredient

A new **Food safety** box on the Ingredient edit screen (*Meal Prep → Ingredients*), next to the existing macros / tier / price-delta box:

| Field | Type | Used for |
|---|---|---|
| Cooking method | dropdown: oven · grill · pan · boil · steam · sous-vide · air fryer · no-cook / ready-to-eat | Method column; no-cook rows |
| Target core temperature | number, defaults to the setting (75 °C) | Target column |
| Typical cook (optional) | free text, e.g. "180 °C fan, 25–30 min" | Printed under the method as a reminder; not a record |
| Cooling required | yes / no (default yes for cooked items) | Whether the cooling blanks print |
| Shelf life (days) | number, default 3 | Use-by column, and later the label (labels currently assume fulfilment date + 3 days for every meal) |
| Storage | chilled · frozen · ambient | Grouping the record by where things go |
| Short code | 3–4 letters, auto-suggested from the name | Batch codes |

Set meals and sweets use the same box; bought-in sweets will mostly be *no-cook* with a supplier use-by.

### 2.5 Settings to add

Under *Meal Prep → Settings*, a **Food safety** tab:

* Critical limits: cook core °C, cool-within minutes, fridge target / maximum °C, dispatch maximum °C, reheat °C.
* Fridges and freezers: a list of names, one line each on the daily checks block.
* Probe calibration frequency (weekly by default).
* Business name / signatory wording for the sheet header.

### 2.6 What does not change

Ordering, checkout, labels, the Prep Dashboard and the three existing Prep Sheet sections are untouched. The record is an extra section rendered from the same data, printed through the same Print / Dompdf route.

---

## 3. Phase 2 — optional, later: digital entry and an archive

Only worth building if the client wants to stop handwriting, or the EHO asks for searchable records.

* **Prep Records page** (tablet-friendly, *Meal Prep → Prep Records*): the same rows as the paper sheet; staff tap a row, type the probe reading, times auto-stamp, initials come from the logged-in user. Out-of-limit readings turn red and require a corrective-action note before the row can be saved.
* **Storage:** two tables, `fn_prep_records` (date, batch code, ingredient, method, portions, readings, user, timestamps, corrective action) and `fn_daily_checks` (fridge temps, calibration, opening/closing, cleaning, sign-off).
* **Archive screen:** filter by date, batch, component or order; export a day or a date range as PDF or CSV for the EHO; automatic retention per a setting.
* **Traceability lookups:** order → batches, batch → orders.
* **Monthly summary:** out-of-limit events and missing checks.

Phase 1's paper sheets stay valid alongside; Phase 2 replaces the pen, not the process.

---

## 4. Related things worth raising with the client (not part of this add-on)

* **Allergen labelling (Natasha's Law).** Meals packed on site and sold to customers directly are "prepacked for direct sale" and need the full ingredient list on the pack with allergens emphasised. The current meal label carries a link to an allergen PDF rather than the list. The ingredients and the Allergen taxonomy already exist in the plugin, so a compliant label line is possible; the client should check what their EHO or Trading Standards expects.
* **Allergen matrix.** A one-page printable matrix (ingredient × 14 allergens) from the existing taxonomy is a small, separate job and is often the first thing an EHO asks to see.

---

## 5. What we need from the client before building

1. Their current SFBB pack or HACCP plan, and anything the EHO has asked for, so the limits and wording match what they already use.
2. For each ingredient: cooking method, any specific core temperature, and shelf life. (Defaults of 75 °C and 3 days can be applied in bulk and corrected later.)
3. Names of their fridges and freezers, and how many probes they use.
4. Whether they cook per component in batches (assumed) or per order.
5. Who signs the sheet, whether they want one sheet per day or per cook session (AM / PM), and how long they keep records.

---

## 6. Rough effort

Estimates, not measured: Phase 1 (ingredient fields, settings tab, Cook & Pack Record section with Dompdf output, batch codes on the pick list) is in the region of two to three working days. Phase 2 (records page, tables, archive, exports) is roughly four to six more. Phase 1 can ship on its own as a normal version bump on `main`.
