# The Tier 0 context budget

Where the number on the Context screen comes from, and how good it is.

Doc 21 shipped with a target of "about 600 tokens" and an explicit instruction
not to render it as a cap until it had been measured. This is that measurement.
Two separate questions had to be answered: **how big is the payload**, and **how
well can we estimate a token count in PHP at all**. The second turned out to be
the interesting one.

## 1. How good is the estimate

The plan was characters ÷ 4, the usual shortcut. Measured against a real
tokeniser it is wrong in a way that would have mattered.

| Text | chars/token | chars ÷ 4 error |
|---|---|---|
| English prose | 5.1 | **+28%** |
| Dutch, German, French, Spanish prose | 3.9 – 4.3 | −3% to +10% |
| Russian prose | 3.4 | −15% |
| Arabic prose | 2.8 | −30% |
| Greek prose | 2.7 | −33% |
| Chinese prose | 1.4 | −64% |
| Japanese prose | 1.3 | **−67%** |
| Hex colours and version strings | 2.6 | −34% |

The divisor is not a property of text; it is a property of the script. A site
owner writing their instructions in Japanese would have been told they cost a
third of what they really cost, and under-reporting is the dangerous direction
for a budget meter, because nothing on screen would ever suggest a problem.

So `TokenEstimator` counts each script at its own rate, and prices the ASCII part
by structure rather than by length: a word by its length, a digit run by its own
rate, and every symbol as a token of its own. That last rule is what brings
`#5344F4, #e9e7ff, #DEC9FF` back to roughly its true price instead of a quarter
of it.

**Result: mean absolute error 15%, band −12% to +32%**, measured on 19 samples,
prose in ten languages plus every section this payload actually renders.

The band is deliberately lopsided. A grid search found parameters scoring 11%
mean error, but it got there by under-reporting some languages by 21%. The
shipped parameters are the principled ones: they err high, which leads an owner
to trim, rather than low, which leads to a surprise.

Calibrated against `o200k_base`. Claude's tokeniser is not public, so this is a
proxy, but the thing being corrected (a Han character costs about a token; a
Latin word costs about a token regardless of length) is a property every modern
byte-pair tokeniser shares, not a quirk of one.

Re-check any change to the parameters with:

```bash
php bin/calibrate-token-estimator.php
```

The corpus and its reference counts live in `bin/calibration/token-corpus.json`.

## 2. How big is the payload

Measured on the same site (WordPress 7.1-RC4, WooCommerce 11.0.1, four public
post types, six taxonomies) across four theme states, plus a synthetic worst
case with every cap saturated and a 470-character instruction.

Estimated tokens per section:

| Section | Ollie (block) | Twenty Twenty-Five | Hello Elementor | Twenty Ten (classic) | Worst case |
|---|---:|---:|---:|---:|---:|
| Site | 19 | 19 | 19 | 19 | 19 |
| Environment | 44 | 45 | 47 | 44 | 44 |
| Design tokens | 132 | 111 | 18 | 51 | 132 |
| Content model | 41 | 41 | 41 | 41 | 117 |
| Commerce | 54 | 54 | 54 | 54 | 54 |
| Skills index | 53 | 53 | 53 | 53 | 53 |
| Safety note | 83 | 83 | 83 | 83 | 83 |
| **Detected total** | **426** | **406** | **315** | **345** | **502** |
| Site instructions | none | none | none | none | 121 |

(The safety note has since been shortened from 83 estimated tokens to 72; it is
the one part of the payload nobody chooses, and a sixth of the total spent on
something the owner cannot switch off was too much. Every figure above is from
before that cut, so each column is about 11 tokens lower today.)

**The payload is small. The 600 target is also meaningless, and those are two
different findings.**

The detected sections land between 315 and 502 estimated tokens across every
state measured, including the synthetic worst case, so 600 is a number real
sites sit inside. That was as far as the first version of this document went, and
on that basis the screen shipped a meter reading "426 of about 600 tokens".

That was the wrong conclusion from the right data. Knowing what the payload
*costs* is not knowing what it *should* cost. Nothing observable happens at 600.
Asked what the number meant to a site owner, there was no answer, and a meter
with no answer behind it is decoration that reads as a warning.

### What the payload is actually up against

Measured on the same site, with a real tokeniser, on the complete
`discover-abilities` response:

| Part of the discovery response | Real tokens | Share |
|---|---:|---:|
| Ability list (85 tools) | 4,411 | 90.8% |
| Site context | 386 | 7.9% |
| Skills index | 47 | 1.0% |
| **Whole response** | **4,860** | 100% |

The context block is **8.9%** of the response it rides in. The tool definitions
alongside it are **ten times larger**. The whole response is 2.4% of a 200k
context window; the context block alone is 0.2%.

So a meter drawn against 600 would warn about the smaller of the two things in
the response, and the only one of them the owner can see on that screen. If
someone genuinely wants to send less per conversation, switching off abilities
they do not use is worth an order of magnitude more than trimming design tokens
and that lever is on a different screen entirely.

### What the screen does instead

Reports the total and the per-section costs, and stops. No denominator, no bar,
no near/over states. Doc 21's open question sanctioned exactly this outcome:
*"If step 3 is not worth the effort, ship per-section costs with no total and no
threshold, still useful, still true."* It turned out the effort was worth it,
it is what established there is no threshold to ship.

The per-section figures still earn their place, as a *relative* comparison.
"Design tokens is 132 of the 477, do I need it" is a decision an owner can act
on. "You are at 71% of a limit we invented" is not.

If a future change makes the payload an order of magnitude larger, a section
that inlines post content, say, this document is where to record the new
measurement, and a limit might then mean something.

### Two things the measurement changed

**Design tokens are the biggest and most variable section.** 18 to 132 tokens,
by far the widest spread. That is the section most worth a switch, and it is the
one whose peek on the screen is worth showing in detail.

**Owner instructions were budgeted separately** (doc 21, correction 5), on the
reasoning that a shared meter would pressure the owner into deleting their brand
voice to protect a section Albert switched on by itself. That reasoning was
sound while there was a meter. Without one there is nothing to be counted against,
and separating the two only produced two totals for one payload, the card said
426 while the preview below it said 477, with nothing on screen to explain the
gap. One total now, every part in it.

## 3. A note on provenance, found while measuring

The design section is gated on the theme genuinely declaring tokens, never on
WordPress's own defaults. Checking that against real themes turned up something
worth writing down: **fonts installed through the Font Library are classified by
WordPress under the `theme` origin regardless of which theme is active.** Twenty
Ten, which ships no `theme.json` at all, reports Inter and Cardo because this
site has them installed.

That is correct behaviour and the gate is right to include them, a font the
owner installed is the site's own choice, which is exactly what the gate is
looking for. It does mean the "no design tokens detected" state is rarer in
practice than the classic-vs-block-theme framing suggests: a classic theme that
declares `add_theme_support( 'editor-color-palette' )`, as Twenty Ten does, has
real brand colours to send.
