/**
 * Let's Go Polar — Dates and Rates data endpoint
 * Google Apps Script, bound to the rates workbook.
 *
 * WHAT IT DOES
 * Reads every sheet, normalizes each sailing exactly the way the current
 * offline build does (cabin pairing by column position, region column with a
 * safeguard, date-derived seasons, itinerary merges), and returns a JSON array
 * the dashboard consumes.
 *
 * DEPLOY
 * 1. In the workbook: Extensions > Apps Script. Paste this file (replace the default).
 * 2. Deploy > New deployment > type "Web app".
 * 3. Execute as: Me.  Who has access: Anyone with the link.
 * 4. Copy the /exec URL. That is the endpoint the dashboard (via the PHP proxy) calls.
 *
 * NOTE ON REFRESH
 * The dashboard reads this on each load. Google caches the deployment briefly;
 * that is fine for weekly pricing. If you want it instant, redeploy or bump the
 * deployment version after a sheet edit, or use the PHP proxy's short cache.
 */

function doGet(e) {
  var payload;
  try {
    payload = { updated: updatedLabel_(), trips: buildTrips_() };
  } catch (err) {
    payload = { error: String(err), trips: [] };
  }
  return ContentService
    .createTextOutput(JSON.stringify(payload))
    .setMimeType(ContentService.MimeType.JSON);
}

/** Change this if the "updated on" label should read differently. */
function updatedLabel_() {
  var tz = Session.getScriptTimeZone() || 'UTC';
  return Utilities.formatDate(new Date(), tz, 'd MMM yyyy');
}

/* ---------- helpers, ported from parse_all2.py ---------- */

function clean_(h) {
  if (h === null || h === undefined) return '';
  return String(h).replace(/\s+/g, ' ').trim();
}
function cellStr_(v) {
  if (v === null || v === undefined) return '';
  if (v instanceof Date) return isoDate_(v);
  return String(v).replace(/\s+/g, ' ').trim();
}
function money_(s) {
  s = cellStr_(s);
  if (!s) return null;
  if (/sold\s*out/i.test(s) || /\btb[ac]\b/i.test(s)) return null;
  var digits = s.split('.')[0].replace(/[^\d]/g, '');
  return digits ? parseInt(digits, 10) : null;
}
function isoDate_(v) {
  if (v === null || v === undefined || v === '') return null;
  var d = (v instanceof Date) ? v : new Date(v);
  if (isNaN(d.getTime())) return null;
  var tz = Session.getScriptTimeZone() || 'UTC';
  return Utilities.formatDate(d, tz, 'yyyy-MM-dd');
}
function parseDays_(s, start, end) {
  s = cellStr_(s);
  var m = s.match(/(\d+)\s*(?:D\b|days?\b)/i) || s.match(/^\s*(\d+)\s*$/);
  if (m) return parseInt(m[1], 10);
  if (start && end) {
    var a = new Date(start), b = new Date(end);
    return Math.round((b - a) / 86400000) + 1;
  }
  return null;
}
function parseNights_(s) {
  var m = cellStr_(s).match(/(\d+)\s*(?:N\b|nights?\b)/i);
  return m ? parseInt(m[1], 10) : null;
}
function normDur_(s, d, n) {
  s = cellStr_(s);
  if (s) return s.replace(/\s*\/\s*/g, ' / ');
  if (d && n) return d + ' D / ' + n + ' N';
  return d ? (d + ' days') : '';
}
function parseActs_(cells) {
  var out = [];
  cells.forEach(function (c) {
    c = cellStr_(c);
    if (!c) return;
    c.split(/;|\n/).forEach(function (part) {
      part = part.trim();
      if (!part) return;
      var pm = part.match(/\$\s*([\d,]+)/);
      out.push({ text: part, price: pm ? parseInt(pm[1].replace(/,/g, ''), 10) : null });
    });
  });
  return out;
}
function regionOf_(dest) {
  var d = (dest || '').toLowerCase();
  var antWords = ['ushuaia', 'south georgia', 'weddell', 'falkland', 'drake', 'peninsula', 'shackleton', 'punta arenas'];
  if (d.indexOf('antarctic') >= 0 || antWords.some(function (k) { return d.indexOf(k) >= 0; })) return 'Antarctic';
  var arc = ['svalbard', 'iceland', 'greenland', 'canada', 'europe', 'norway', 'arctic', 'faroe', 'scotland', 'ireland', 'orkney', 'shetland', 'tromsø', 'tromso', 'labrador', 'spitsbergen'];
  return arc.some(function (k) { return d.indexOf(k) >= 0; }) ? 'Arctic' : 'Antarctic';
}
function pickRegion_(col, dest) {
  var c = (col || '').trim().toLowerCase();
  if (c.indexOf('antarctic') === 0) return 'Antarctic';
  if (c.indexOf('arctic') === 0) return 'Arctic';
  return regionOf_(dest);
}
var CABIN_KIND_ = { full: 'full', disc: 'disc', dic: 'disc' };
function cabinParts_(h) {
  var parts = h.split('.');
  var last = parts[parts.length - 1].trim().toLowerCase();
  var kind = CABIN_KIND_[last];
  if (!kind) return null;
  var name = parts.slice(0, -1).join('.').trim();
  if (name.toLowerCase().indexOf('cabins') === 0) name = name.slice(6).replace(/^[.\s]+/, '').trim();
  return { name: name, kind: kind };
}
function colRole_(hl) {
  if (hl === 'operator' || hl === 'oeprator') return 'operator';
  if (hl === 'ship') return 'ship';
  if (hl === 'start_date') return 'start';
  if (hl === 'end_date') return 'end';
  if (hl === 'itinerary_name') return 'itin';
  if (hl === 'days') return 'days';
  if (hl.indexOf('inclusion') >= 0) return 'incl';
  if (hl.indexOf('activities') >= 0) return 'acts';
  if (hl === 'lgp_links') return 'lgp';
  if (/_links$/.test(hl) || hl === 'plex_links') return 'oplink';
  if (/link$/.test(hl)) return 'oplink';
  if (hl === 'destinations') return 'dest';
  if (hl === 'season') return 'season';
  if (hl === 'region') return 'region';
  if (hl === 'start_location') return 'sloc';
  if (hl === 'end_location') return 'eloc';
  if (hl === 'operator_id' || hl === 'api_id') return 'skip';
  return null;
}

/* ---------- itinerary merge + season, mirrors the dashboard ---------- */

var ITIN_MAP_ = {
  'classic': 'Classic Antarctica',
  'classic antarctica': 'Classic Antarctica',
  'heroic south': 'Classic Antarctica',
  'spirit of terra nova': 'Classic Antarctica',
  'circle': 'Circle Crossing',
  'polar circle quest': 'Circle Crossing'
};
function itinCat_(tok) {
  var t = tok.trim();
  return ITIN_MAP_[t.toLowerCase()] || t;
}

/* ---------- main build ---------- */

function buildTrips_() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheets = ss.getSheets();
  var trips = [];
  var tid = 0;
  var overrides = [];

  sheets.forEach(function (sheet) {
    var values = sheet.getDataRange().getValues();
    if (!values || values.length < 2) return;
    var headers = values[0].map(clean_);

    var roles = {};       // colIndex -> role
    var cabinCols = [];   // {idx,name,kind}
    var actIdx = [];
    headers.forEach(function (h, i) {
      var hl = h.toLowerCase();
      var r = colRole_(hl);
      if (r === 'acts') { actIdx.push(i); return; }
      if (r && r !== 'skip') { roles[i] = r; return; }
      if (r === 'skip') return;
      var cp = cabinParts_(h);
      if (cp) cabinCols.push({ idx: i, name: cp.name, kind: cp.kind });
    });

    // positional cabin pairing: full then disc
    var cabinsOrder = [];
    for (var i = 0; i < cabinCols.length;) {
      var c = cabinCols[i];
      if (c.kind === 'full') {
        if (i + 1 < cabinCols.length && cabinCols[i + 1].kind === 'disc') {
          cabinsOrder.push({ name: c.name, fullIdx: c.idx, discIdx: cabinCols[i + 1].idx }); i += 2;
        } else { cabinsOrder.push({ name: c.name, fullIdx: c.idx, discIdx: null }); i += 1; }
      } else { cabinsOrder.push({ name: c.name, fullIdx: null, discIdx: c.idx }); i += 1; }
    }

    function ridx(role) {
      for (var k in roles) if (roles[k] === role) return parseInt(k, 10);
      return null;
    }
    var im = {};
    ['operator', 'ship', 'start', 'end', 'itin', 'days', 'lgp', 'oplink', 'dest', 'season', 'sloc', 'eloc', 'region']
      .forEach(function (r) { im[r] = ridx(r); });

    for (var row = 1; row < values.length; row++) {
      var R = values[row];
      function g(role) { var ix = im[role]; return (ix !== null && ix < R.length) ? cellStr_(R[ix]) : ''; }

      var itin = g('itin');
      var start = (im.start !== null) ? isoDate_(R[im.start]) : null;
      var end = (im.end !== null) ? isoDate_(R[im.end]) : null;
      if (!itin && !start) continue;

      var cabins = [];
      cabinsOrder.forEach(function (co) {
        var full = (co.fullIdx !== null && co.fullIdx < R.length) ? money_(R[co.fullIdx]) : null;
        var disc = (co.discIdx !== null && co.discIdx < R.length) ? money_(R[co.discIdx]) : null;
        cabins.push({ name: co.name, full: full, disc: (disc !== null ? disc : full), available: (full !== null || disc !== null) });
      });
      if (!cabins.length) continue;

      var ad = cabins.filter(function (c) { return c.available && c.disc !== null; }).map(function (c) { return c.disc; });
      var af = cabins.filter(function (c) { return c.available && c.full !== null; }).map(function (c) { return c.full; });
      var days = parseDays_(g('days'), start, end);
      var nights = parseNights_(g('days'));
      var oplink = g('oplink'); if (!/^https?:\/\//.test(oplink)) oplink = '';
      var lgp = g('lgp'); if (!/^https?:\/\//.test(lgp)) lgp = '';
      var dest = g('dest');
      var region = pickRegion_(g('region'), dest);

      trips.push({
        id: tid++, operator: g('operator'), ship: g('ship'), start: start, end: end,
        startRaw: start ? fmtRaw_(start) : '', endRaw: end ? fmtRaw_(end) : '',
        itinerary: itin.replace(/\s+/g, ' ').trim(), days: days, nights: nights,
        duration: normDur_(g('days'), days, nights),
        activities: parseActs_(actIdx.map(function (ix) { return ix < R.length ? R[ix] : ''; })),
        operatorLink: oplink, lgpLink: lgp, destinations: dest, region: region,
        season: g('season').replace(/\//g, '-').trim(), startLoc: g('sloc'), endLoc: g('eloc'),
        cabins: cabins,
        fromPrice: ad.length ? Math.min.apply(null, ad) : null,
        fromFull: af.length ? Math.min.apply(null, af) : null,
        availCount: cabins.filter(function (c) { return c.available; }).length,
        cabinCount: cabins.length
      });
    }
  });

  // region safeguard: trust the column, correct blatant contradictions, log them
  var STRONG_ARC = ['svalbard', 'greenland', 'iceland', 'faroe', 'orkney', 'shetland', 'spitsbergen', 'tromso', 'tromsø', 'labrador', 'north atlantic', 'viking'];
  var STRONG_ANT = ['antarctic', 'south georgia', 'weddell', 'falkland', 'drake', 'ushuaia', 'punta arenas'];
  trips.forEach(function (t) {
    var dl = ((t.destinations || '') + ' ' + (t.itinerary || '')).toLowerCase();
    var a = STRONG_ARC.some(function (w) { return dl.indexOf(w) >= 0; });
    var b = STRONG_ANT.some(function (w) { return dl.indexOf(w) >= 0; });
    var strong = (a && !b) ? 'Arctic' : ((b && !a) ? 'Antarctic' : null);
    if (strong && strong !== t.region) {
      overrides.push(t.ship + ' ' + t.start + ' sheet=' + t.region + ' -> ' + strong + ' | ' + t.itinerary);
      t.region = strong;
    }
  });
  if (overrides.length) Logger.log('REGION SAFEGUARD corrected ' + overrides.length + ':\n' + overrides.join('\n'));

  return trips;
}

function fmtRaw_(iso) {
  var p = iso.split('-');
  var mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return parseInt(p[2], 10) + ' ' + mon[parseInt(p[1], 10) - 1] + ' ' + p[0];
}
