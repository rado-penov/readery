# Amazon Orders Converter

**Version 2.0** · © 2026 NS Nexus Limited. All rights reserved.

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

- Log-in with **Gardners Account ID + decimal PIN** (`####.##`). Each company has its own counters and order history.
- Order files are built in the browser. Customer data is never sent to the server; only Amazon order numbers and line counts go to the number service. No company names, people or e-mails are stored.
- Sequence numbers and line references come from a small PHP service on the hosting (`counter.php`), so every computer uses the same numbers.
- Reports can be uploaded together or one at a time, and are merged automatically.
- Each order is shown as a card with warnings before download. Prices can be edited.
- Download per order, or all orders as a `.zip`.

## Conversion rules

| Gardners field | Source |
|---|---|
| `HEADER` account | **Gardners Account ID** from Settings (default `NSN001`) |
| `HEADER` date | Today, `DD/MM/YYYY` |
| `HEADER` sequence | Running 9-digit number, unique for every order file (`000000001`, `000000002`, …). Stored in the browser as **Next sequence number** in Settings |

The header sequence must be unique for every order ever sent (alphanumeric, max 20 characters): it never restarts, so after `999999999` it continues to `1000000000`. The line reference is numeric only and runs `000000001` to `999999999`, then starts again at `000000001`.

## Accounts and log-in (v2.0)

- **Log in** with the Gardners Account ID and a PIN typed as 4 digits, a dot and 2 digits (two boxes), e.g. `1234.56`.
- If the account doesn't exist: *"The customer is not existing. Would you like to create it?"* → confirm the PIN → **Create**.
- **Reset PIN**: company ID, confirm company ID, new PIN, confirm new PIN. Resetting logs the account out everywhere.
- 5 wrong PINs in a row lock that account's log-in for 15 minutes; at most 3 PIN resets per account per 24 hours.
- A log-in lasts 12 hours from the last use, in that browser tab. **Log out** is at the top right.
- After log-in, Settings → Shared numbers shows when the PIN was last changed.

**Known limitation (by design):** Reset PIN does not ask for the old PIN, so anyone who knows an account ID can reset that account's PIN. The limits above and the "PIN last changed" note reduce the risk; keep the folder password-protected as well.

## Number service (`counter.php`)

Per Gardners Account ID the server keeps only: PIN hash (bcrypt), PIN-changed time, next sequence number, next line reference, the numbers issued to each Amazon order ID, hashed session tokens, and failed-PIN / reset counters. No customer data.

- Numbers are issued when an order is downloaded. The file is locked during each change, so two computers never get the same number.
- Each Amazon order keeps its numbers. Converting it again, on any computer, reuses them and shows **Already converted**; **Issue new numbers** is for a deliberate resend (old numbers are kept in history).
- **Settings → Shared numbers** sets the next numbers; the sequence can only be increased.
- If the service can't be reached, downloads switch off so numbers are never reused.

All requests are JSON `POST` to `counter.php`. Calls after log-in send the token in the `X-Session` header; the account is taken from the token, never from the request.

| action | needs log-in | does |
|---|---|---|
| `exists` | no | `account` → whether it has a PIN |
| `create` | no | `account`, `pin`, `pinConfirm` → new account (or first PIN for a v1.4 account), returns token |
| `login` | no | `account`, `pin` → token |
| `resetpin` | no | `account`, `accountConfirm`, `pin`, `pinConfirm` → new PIN, ends all sessions, returns token |
| `logout` | token | ends this session |
| `status` | yes | account, `nextSeq`, `nextRef`, `pinChanged` |
| `lookup` | yes | `ids: [...]` → numbers already issued |
| `reserve` | yes | `orders: [{id, lines}]`, optional `renew: [ids]` → issue or reuse numbers |
| `set` | yes | `nextSeq` (only upwards), `nextRef` |

**Data file:** `data/counters.php` - JSON behind a PHP guard line, so opening it in a browser returns nothing; `data/.htaccess` also blocks the folder. A v1.4 `data/counters.json` is moved in automatically on first use (a copy is kept as `data/counters-v1-backup.php`).

**Hosting:** PHP 7.4 or later. Upload `index.html` and `counter.php` to `public_html/orderconverter/`. Back up `data/counters.php` regularly: it is the record of every number issued. After upgrading from v1.4, **create NSN001 straight away** so its existing counters get your PIN.

## Project structure

```
Order Converter/
├── index.html                                  Built, self-contained page: upload to the hosting
├── counter.php                                 Accounts and number service: upload next to index.html
├── README.md
├── Amazon-Orders-Converter-Service-Manual.docx  Technical / service manual
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
4. Upload the new `index.html` (and `counter.php` if changed) to `public_html/orderconverter/` on the hosting.
   Testing locally needs PHP: `php -S 127.0.0.1:8000` in this folder, then open `http://127.0.0.1:8000/`.

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

**v2.0** (major)
- Log-in with Gardners Account ID + decimal PIN (`####.##`), Create customer, Reset PIN, Log out
- Counters and order history are per account; the account comes from the log-in, not from Settings
- PIN stored only as a bcrypt hash; wrong-PIN lock, reset limit, sessions end on PIN reset
- Data file stored as `counters.php` behind a guard line; v1.4 data migrated automatically
- "Please read the User Manual before using the tool" on the log-in page
- Service manual added

**v1.4**
- Shared number service (`counter.php`) on the hosting: the sequence and line reference counters are the same on every computer
- Orders keep their numbers; converting an order again shows **Already converted** and reuses them
- **Issue new numbers** for a deliberate resend
- Downloads switch off if the number service can't be reached

**v1.3**
- Unique reference is now a 9-digit running number per order line (`000000001` … `999999999`, then restarts), as requested by Gardners
- New setting: Next line reference
- Header sequence never restarts (Gardners: unique for all orders ever sent, max 20 characters)

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
