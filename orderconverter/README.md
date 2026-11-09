# Amazon Orders Converter

**Version 1.3** · © 2026 NS Nexus Limited. All rights reserved.

A single-page web tool that turns Amazon Seller Central order reports into
Gardners **i12 `.ORD`** order files: one file per Amazon order, ready to send to
Gardners for dropship fulfilment.

Live at: `https://readery.co.uk/orderconverter/`

---

## What it does

| In | Out |
|---|---|
| **Unshipped Orders** report (`.txt`, required): customer, address, SKU, quantity | One Gardners `.ORD` file per Amazon order |
| **All Orders** report (`.txt`, optional): item and shipping prices | Multi-book orders as one file with a `DETAIL` block per book |

- Runs entirely in the browser. No order data is sent to the server.
- Reports can be uploaded together or one at a time, and are merged automatically.
- Each order is shown as a card with warnings before download. Prices can be edited.
- Download per order, or all orders as a `.zip`.

## Conversion rules

| Gardners field | Source |
|---|---|
| `HEADER` account | **Gardners Account ID** from Settings (default `NSN001`) |
| `HEADER` date | Today, `DD/MM/YYYY` |
| `HEADER` sequence | Running 9-digit number, unique for every order file (`000000001`, `000000002`, …). Stored in the browser as **Next sequence number** in Settings |

Both counters run from `000000001` to `999999999`, then start again at `000000001`. Re-downloading the same order in one session reuses its numbers.
| `DETAIL` line number | `000000001`, `000000002`, … within each file |
| `DETAIL` reference | Unique per order line: a running 9-digit number (`000000001`, `000000002`, …), one per book line across all orders. Stored in the browser as **Next line reference** in Settings |
| `DETAIL` ISBN | Amazon `sku` with the prefix removed (default `BK-`) and validated as ISBN-10/13 |
| `DETAIL` quantity | `quantity-to-ship` (falls back to `quantity-purchased`) |
| `TITLENAME` / `INITIALS` / `INAME` | Split from `buyer-name` (`D…` fields from `recipient-name`) |
| `IADDR1-4` / `DADDR1-4` | Address lines (a bare house number is merged with the street), city, county |
| `IPCODE` / `DPCODE` | `ship-postal-code` |
| `ICOUNTRY` / `DCOUNTRY` | `ship-country` code converted to name (`GB` → `UNITED KINGDOM`) |
| `PRICE` / `DELIVERY` | All Orders `item-price` / `shipping-price` in pence (`9.99` → `999`) |
| `SERVICE` | Settings code for Standard or Expedited orders |
| `TRACKINGEMAIL` | Customer's Amazon email, always included |
| `TRACKINGSMS` | Customer phone, only when Tracking = `Y` |
| `TRAILER` | Number of `DETAIL` lines |

**File name:** `<Account ID><DDMMYY><last 3 digits of order>.ORD`, e.g. `NSN001091126161.ORD`.

Output uses Windows line endings (CRLF) and plain ASCII by default, matching Gardners' samples.

## Project structure

```
Order Converter/
├── index.html                                  Built, self-contained page: this is what gets hosted
├── README.md
├── Amazon-Orders-Converter-User-Manual.docx    User manual (Word)
├── Amazon-Orders-Converter-User-Manual.pptx    User manual (slides)
├── User Manual.pdf                             User manual (PDF)
├── Converter-Settings-Reference.xlsx           Every setting and its default
├── Gardners-i12-questions.txt                  Open questions for Gardners
├── src/
│   ├── page.html       Page layout, Readery styling and UI logic
│   └── core.js         Parsing and .ORD generation (also runs in Node for testing)
├── tools/
│   └── build.py        Inlines src/core.js into src/page.html → index.html
└── samples/
    ├── Unshipped-Orders-demo.txt         Demo Amazon report (made-up customers)
    ├── All-Orders-demo.txt               Demo Amazon prices report
    ├── gardners-i12-single-line-example.ORD
    └── gardners-i12-multi-line-example.ORD
```

## Making changes

1. Edit `src/page.html` (layout, styles, UI) or `src/core.js` (conversion logic).
2. Rebuild: `python tools/build.py`
3. Test with the files in `samples/`: upload both demo reports and check the two orders
   (one single-line, one multi-line).
4. Upload the new `index.html` to `public_html/orderconverter/` on the hosting.

When releasing a new version, update the version number in `src/page.html`
(the comment at the top and the two footer notice strings).

### Testing core.js in Node

```js
const fs = require('fs');
const C = require('./src/core.js');
const unshipped = C.parseTsv(C.decodeBuffer(fs.readFileSync('samples/Unshipped-Orders-demo.txt')));
const prices = C.parseTsv(C.decodeBuffer(fs.readFileSync('samples/All-Orders-demo.txt')));
const settings = C.DEFAULT_SETTINGS;
for (const order of C.buildOrders(unshipped.rows, C.buildPriceIndex(prices.rows), settings)) {
  console.log(C.fileNameFor(order, settings), order.warnings);
  console.log(C.renderOrd(order, settings));
}
```

## Open points to confirm with Gardners

See `Gardners-i12-questions.txt`. In short:
- Meaning of the `HEADER` 4th field (`Y`) and the last `DETAIL` field (`0`)
- Full list of `SERVICE` codes (tracked, signed-for, next-day)
- Unit and behaviour of `MAXWAIT`
- Whether `PRICE` / `DELIVERY` print on the despatch note
- The sender address for tracking emails (to register in Seller Central)

## Changelog

**v1.3**
- Unique reference is now a 9-digit running number per order line (`000000001` … `999999999`, then restarts), as requested by Gardners
- New setting: Next line reference
- Header sequence also restarts at `000000001` after `999999999`

**v1.2**
- Header sequence is a running number, unique for every order file (Gardners duplication check)
- Unique reference for every order line: order digits + line number (Gardners requirement)
- Settings: "Header sequence" replaced by "Next sequence number"
- Order cards show the header sequence and each line's reference

**v1.1**
- Gardners Account ID is now a setting (default `NSN001`), so other companies can use the converter
- Downloads are disabled until an Account ID is entered
- Version number added to the footer notice

**v1.0**
- Header account `NSN001`
- Reference shortened to 15 characters (first block of the Amazon order ID dropped)
- File name `NSN001` + `DDMMYY` + last 3 order digits
- Customer email always sent in `TRACKINGEMAIL`
- Readery look: colours, Literata / Hanken Grotesk, header and footer
- Protected ownership notice in the footer

## Ownership

The Amazon Orders Converter is the property of NS Nexus Limited.
© 2026 NS Nexus Limited. All rights reserved.
