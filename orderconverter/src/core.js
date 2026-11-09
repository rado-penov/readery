(function (root) {
  'use strict';

  const DEFAULT_SETTINGS = {
    account: 'NSN001',
    headerFlag: 'Y',
    nextSeq: '1',
    nextRef: '1',
    detailFlag: '0',
    skuPrefix: 'BK-',
    serviceStandard: '001',
    serviceExpedited: '001',
    giftwrap: 'N',
    signature: 'N',
    tracking: 'N',
    useContactForTracking: true,
    trackingSafePlace: '',
    batchRef: '',
    maxWait: '1',
    comm1: 'Thank you for your purchase',
    comm2: '',
    comm3: '',
    comm4: '',
    asciiOnly: true,
    crlf: true
  };

  const COUNTRIES = {
    GB: 'UNITED KINGDOM', UK: 'UNITED KINGDOM', IE: 'IRELAND', JE: 'JERSEY', GG: 'GUERNSEY',
    IM: 'ISLE OF MAN', US: 'UNITED STATES', FR: 'FRANCE', DE: 'GERMANY', ES: 'SPAIN',
    IT: 'ITALY', NL: 'NETHERLANDS', BE: 'BELGIUM', PT: 'PORTUGAL', AT: 'AUSTRIA',
    SE: 'SWEDEN', DK: 'DENMARK', PL: 'POLAND', CH: 'SWITZERLAND', NO: 'NORWAY'
  };

  const DEFAULT_ACCOUNT = 'NSN001'; // default Gardners account ID (editable in Settings)

  function accountId(settings) {
    return String((settings && settings.account) || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
  }

  const TITLES = ['MR', 'MRS', 'MS', 'MISS', 'DR', 'PROF', 'MX', 'SIR', 'REV'];

  /* ---------- file reading ---------- */
  function decodeBuffer(buf) {
    let bytes = new Uint8Array(buf);
    if (bytes[0] === 0xEF && bytes[1] === 0xBB && bytes[2] === 0xBF) bytes = bytes.subarray(3);
    try { return new TextDecoder('utf-8', { fatal: true }).decode(bytes); }
    catch (e) { return new TextDecoder('windows-1252').decode(bytes); }
  }

  function parseTsv(text) {
    const lines = text.replace(/^\uFEFF/, '').split(/\r?\n/).filter(l => l.trim() !== '');
    if (!lines.length) return { headers: [], rows: [] };
    const headers = lines[0].split('\t').map(h => h.trim().toLowerCase());
    const rows = lines.slice(1).map(l => {
      const cells = l.split('\t');
      const o = {};
      headers.forEach((h, i) => { o[h] = (cells[i] === undefined ? '' : cells[i]).trim(); });
      return o;
    });
    return { headers, rows };
  }

  // 'orders'    = Unshipped / New Orders report (has full address)
  // 'allorders' = All Orders report (prices only)
  function detectType(headers) {
    const has = h => headers.indexOf(h) !== -1;
    if (has('order-id') && has('ship-address-1')) return 'orders';
    if (has('amazon-order-id') && has('item-price')) return 'allorders';
    return 'unknown';
  }

  function toPence(v) {
    if (v === undefined || v === null || String(v).trim() === '') return null;
    const n = parseFloat(String(v).replace(/[^0-9.\-]/g, ''));
    return isNaN(n) ? null : Math.round(n * 100);
  }

  function buildPriceIndex(allOrdersRows) {
    const idx = new Map();
    for (const r of allOrdersRows) {
      if (/cancel/i.test(r['item-status'] || '')) continue;
      const entry = { price: toPence(r['item-price']), delivery: toPence(r['shipping-price']) };
      const id = r['amazon-order-id'];
      if (r['order-item-id']) idx.set(id + '|' + r['order-item-id'], entry);
      if (r['sku']) idx.set(id + '|sku|' + r['sku'], entry);
    }
    return idx;
  }

  /* ---------- helpers ---------- */
  function clean(v, settings) {
    let s = (v === undefined || v === null) ? '' : String(v);
    s = s.replace(/[\t\r\n]+/g, ' ').replace(/"/g, "'").replace(/\s{2,}/g, ' ').trim();
    if (settings && settings.asciiOnly) {
      s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[\u2018\u2019]/g, "'").replace(/[\u201C\u201D]/g, "'")
        .replace(/[\u2013\u2014]/g, '-').replace(/ß/g, 'ss').replace(/[łŁ]/g, m => m === 'ł' ? 'l' : 'L')
        .replace(/[^\x20-\x7E]/g, '');
    }
    return s;
  }

  function pad(n, len) { return String(n).padStart(len, '0'); }

  function skuToIsbn(sku, prefix) {
    let s = (sku || '').trim();
    if (prefix && s.toUpperCase().startsWith(prefix.toUpperCase())) s = s.slice(prefix.length);
    return s.replace(/[-\s]/g, '');
  }

  function isValidIsbn(s) {
    if (/^97[89]\d{10}$/.test(s)) {
      let sum = 0;
      for (let i = 0; i < 12; i++) sum += (+s[i]) * (i % 2 ? 3 : 1);
      return (10 - (sum % 10)) % 10 === +s[12];
    }
    if (/^\d{9}[\dX]$/i.test(s)) {
      let sum = 0;
      for (let i = 0; i < 10; i++) sum += (s[i].toUpperCase() === 'X' ? 10 : +s[i]) * (10 - i);
      return sum % 11 === 0;
    }
    return false;
  }

  function splitName(full) {
    const parts = (full || '').trim().split(/\s+/).filter(Boolean);
    let title = '';
    if (parts.length > 1 && TITLES.indexOf(parts[0].replace(/\./g, '').toUpperCase()) !== -1) {
      title = parts.shift().replace(/\./g, '');
    }
    if (!parts.length) return { title, initials: '', surname: '' };
    if (parts.length === 1) return { title, initials: '', surname: parts[0] };
    const surname = parts[parts.length - 1];
    const initials = parts.slice(0, -1).map(p => p[0].toUpperCase()).join('');
    return { title, initials, surname };
  }

  function buildAddress(r) {
    let lines = [r['ship-address-1'], r['ship-address-2'], r['ship-address-3']]
      .map(x => (x || '').trim()).filter(Boolean);
    // Amazon often puts a bare house number on its own line ("36") - merge it with the street
    if (lines.length > 1 && /^(flat\s+|unit\s+|apt\.?\s+)?\d+[a-z]?(\s*[-\/]\s*\d+[a-z]?)?,?$/i.test(lines[0])) {
      const sep = /^(flat|unit|apt)/i.test(lines[0]) ? ', ' : ' ';
      lines = [lines[0].replace(/,$/, '') + sep + lines[1]].concat(lines.slice(2));
    }
    const cc = (r['ship-country'] || '').trim().toUpperCase();
    return {
      addr1: lines[0] || '',
      addr2: lines.slice(1).join(', '),
      town: r['ship-city'] || '',
      county: r['ship-state'] || '',
      postcode: (r['ship-postal-code'] || '').toUpperCase(),
      country: COUNTRIES[cc] || cc
    };
  }

  /* ---------- orders ---------- */
  function buildOrders(rows, priceIndex, settings) {
    const map = new Map();
    for (const r of rows) {
      const id = r['order-id'];
      if (!id) continue;
      if (!map.has(id)) {
        map.set(id, {
          orderId: id,
          row: r,
          buyerName: r['buyer-name'] || r['recipient-name'] || '',
          recipientName: r['recipient-name'] || r['buyer-name'] || '',
          address: buildAddress(r),
          lines: [],
          skipped: 0,
          warnings: []
        });
      }
      const o = map.get(id);
      const qtyRaw = r['quantity-to-ship'] !== undefined && r['quantity-to-ship'] !== ''
        ? r['quantity-to-ship'] : r['quantity-purchased'];
      const qty = parseInt(qtyRaw, 10) || 0;
      if (qty <= 0) { o.skipped++; continue; }

      const isbn = skuToIsbn(r['sku'], settings.skuPrefix);
      let price = toPence(r['item-price']);
      let delivery = toPence(r['shipping-price']);
      let priceSource = price !== null ? 'report' : null;
      if (price === null && priceIndex) {
        const p = priceIndex.get(id + '|' + r['order-item-id']) || priceIndex.get(id + '|sku|' + r['sku']);
        if (p) { price = p.price; delivery = p.delivery; priceSource = 'All Orders file'; }
      }
      o.lines.push({
        key: id + '|' + (r['order-item-id'] || r['sku']),
        itemId: r['order-item-id'] || '',
        sku: r['sku'] || '',
        title: r['product-name'] || '',
        isbn,
        isbnValid: isValidIsbn(isbn),
        qty,
        price,
        delivery: delivery === null ? 0 : delivery,
        priceSource
      });
    }

    for (const o of map.values()) {
      const w = o.warnings;
      if (!o.lines.length) w.push('Nothing left to ship on this order.');
      o.lines.forEach(l => {
        if (!l.isbnValid) w.push(`SKU "${l.sku}" doesn't give a valid ISBN (${l.isbn || 'empty'}).`);
        if (l.price === null) w.push(`No price found for ${l.sku}. Add the All Orders file or enter it below.`);
      });
      if (!o.address.addr1) w.push('Delivery address line 1 is empty.');
      if (!o.address.postcode) w.push('Postcode is empty.');
      if (o.address.country && o.address.country.length <= 2) w.push(`Unknown country code "${o.address.country}".`);
    }
    return Array.from(map.values());
  }

  function todayStr(d) {
    d = d || new Date();
    return pad(d.getDate(), 2) + '/' + pad(d.getMonth() + 1, 2) + '/' + d.getFullYear();
  }

  function renderOrd(order, settings, dateStr, overrides, seq, refs) {
    refs = refs || refsFrom(settings.nextRef, order.lines.length);
    overrides = overrides || {};
    const c = v => clean(v, settings);
    const q = v => '"' + c(v) + '"';
    const num = v => String(v).replace(/[^0-9]/g, '') || '0';
    const inv = splitName(order.buyerName);
    const del = splitName(order.recipientName);
    const a = order.address;
    const r = order.row;
    const service = /standard|std/i.test(r['ship-service-level'] || 'Standard')
      ? settings.serviceStandard : settings.serviceExpedited;
    const tracking = settings.tracking === 'Y';
    const email = r['buyer-email'] || '';
    const sms = tracking && settings.useContactForTracking ? (r['buyer-phone-number'] || '').replace(/\s+/g, '') : '';

    const out = [];
    out.push(`"HEADER",${q(accountId(settings))},${q(dateStr || todayStr())},${q(settings.headerFlag)},${q(formatHeaderSeq(seq || settings.nextSeq))}`);
    order.lines.forEach((l, i) => {
      const ov = overrides[l.key] || {};
      const price = ov.price !== undefined ? ov.price : (l.price === null ? 0 : l.price);
      const delivery = ov.delivery !== undefined ? ov.delivery : l.delivery;
      out.push(`"DETAIL",${pad(i + 1, 9)},${q(formatSeq(refs[i]))},${q(l.isbn)},${l.qty},${num(settings.detailFlag)}`);
      out.push(`"TITLENAME",${q(inv.title)}`);
      out.push(`"INITIALS",${q(inv.initials)}`);
      out.push(`"INAME",${q(inv.surname)}`);
      out.push(`"IADDR1",${q(a.addr1)}`);
      out.push(`"IADDR2",${q(a.addr2)}`);
      out.push(`"IADDR3",${q(a.town)}`);
      out.push(`"IADDR4",${q(a.county)}`);
      out.push(`"IPCODE",${q(a.postcode)}`);
      out.push(`"ICOUNTRY",${q(a.country)}`);
      out.push(`"DTITLENAME",${q(del.title)}`);
      out.push(`"DINITIALS",${q(del.initials)}`);
      out.push(`"DNAME",${q(del.surname)}`);
      out.push(`"DADDR1",${q(a.addr1)}`);
      out.push(`"DADDR2",${q(a.addr2)}`);
      out.push(`"DADDR3",${q(a.town)}`);
      out.push(`"DADDR4",${q(a.county)}`);
      out.push(`"DPCODE",${q(a.postcode)}`);
      out.push(`"DCOUNTRY",${q(a.country)}`);
      out.push(`"PRICE",${Math.max(0, Math.round(price))}`);
      out.push(`"DELIVERY",${Math.max(0, Math.round(delivery || 0))}`);
      out.push(`"SERVICE",${num(service)}`);
      out.push(`"GIFTWRAP",${q(settings.giftwrap)}`);
      out.push(`"SIGNATURE",${q(settings.signature)}`);
      out.push(`"TRACKING",${q(settings.tracking)}`);
      out.push(`"TRACKINGEMAIL",${q(email)}`);
      out.push(`"TRACKINGSMS",${q(sms)}`);
      out.push(`"TRACKINGSAFEPLACE",${q(settings.trackingSafePlace)}`);
      out.push(`"BATCHREF",${q(settings.batchRef)}`);
      out.push(`"MAXWAIT",${num(settings.maxWait)}`);
      out.push(`"COMM1",${q(settings.comm1)}`);
      out.push(`"COMM2",${q(settings.comm2)}`);
      out.push(`"COMM3",${q(settings.comm3)}`);
      out.push(`"COMM4",${q(settings.comm4)}`);
    });
    out.push(`"TRAILER",${pad(order.lines.length, 6)}`);
    const eol = settings.crlf ? '\r\n' : '\n';
    return out.join(eol) + eol;
  }

  // Gardners reference: max 15 chars. Amazon IDs are 3-7-7 (e.g. 204-6947430-4469161),
  // so drop the first block -> 6947430-4469161
  function refFor(orderId) {
    const id = String(orderId || '').trim();
    const m = id.match(/^\d{3}-(\d{7}-\d{7})$/);
    return m ? m[1] : id.slice(-15);
  }

  // Line reference: 9-digit running counter (Gardners: numeric only),
  // 000000001 ... 999999999, then back to 000000001
  const MAX_COUNTER = 999999999;
  function toCounter(n) {
    const v = parseInt(String(n == null ? '' : n).replace(/\D/g, ''), 10);
    if (isNaN(v) || v < 1) return 1;
    return v > MAX_COUNTER ? ((v - 1) % MAX_COUNTER) + 1 : v;
  }
  function nextCounter(n) {
    const v = toCounter(n);
    return v >= MAX_COUNTER ? 1 : v + 1;
  }
  function formatSeq(n) { return pad(toCounter(n), 9); }

  // Header sequence (Gardners: unique for every order ever sent, max 20 characters).
  // Never restarts: 000000001, 000000002 ... 999999999, 1000000000 ...
  function toSeq(n) {
    const v = parseInt(String(n == null ? '' : n).replace(/\D/g, '').slice(0, 15), 10);
    return isNaN(v) || v < 1 ? 1 : v;
  }
  function formatHeaderSeq(n) { return pad(toSeq(n), 9); }
  // Line references for an order, starting from a counter value
  function refsFrom(start, count) {
    const out = []; let r = toCounter(start);
    for (let i = 0; i < count; i++) { out.push(r); r = nextCounter(r); }
    return out;
  }

  // File name: NSN001 + DDMMYY (today) + last 3 digits of the Amazon order ID
  function fileNameFor(order, settings, d) {
    d = d || new Date();
    const ddmmyy = pad(d.getDate(), 2) + pad(d.getMonth() + 1, 2) + String(d.getFullYear()).slice(-2);
    const last3 = String(order.orderId).replace(/\D/g, '').slice(-3);
    return accountId(settings) + ddmmyy + last3 + '.ORD';
  }

  const api = {
    DEFAULT_SETTINGS, decodeBuffer, parseTsv, detectType, buildPriceIndex, buildOrders,
    renderOrd, fileNameFor, refFor, formatSeq, formatHeaderSeq, toSeq, toCounter, nextCounter, refsFrom, accountId, DEFAULT_ACCOUNT, todayStr, splitName, buildAddress, isValidIsbn, skuToIsbn
  };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.OrdConverter = api;
})(typeof window !== 'undefined' ? window : globalThis);
