(function () {
  "use strict";

  // The DOM-free half of the page, spliced in ahead of this file. Named here rather than reached
  // through LockrotLib at every call site, so the several hundred uses of esc() read as they did.
  var esc = LockrotLib.esc;
  var safeHref = LockrotLib.safeHref;
  var installCommand = LockrotLib.installCommand;
  var kvRows = LockrotLib.kvRows;

  var BUNDLE = JSON.parse(document.getElementById("lockrot-data").textContent);
  var REPORT = BUNDLE.report;
  var DETAILS = BUNDLE.details || {};
  // What the run was told to do, and the vocabulary it used. It rides inside the report, so a
  // document straight from `--format=json` carries it too and this page needs nothing beside it.
  var RUN = REPORT.run || {};
  var TOOL = REPORT.lockrot || {};
  var FINDINGS = REPORT.findings || [];
  var NOW = new Date(REPORT.generated_at);

  var PRIORITIES = ["critical", "high", "medium", "low", "none"];
  var VERDICTS = ["abandoned", "silent", "pinned", "left-behind", "old-promise", "stale", "unknown", "finished", "ok"];
  var TONE = {
    critical: "crit", high: "high", medium: "med", low: "low", none: "none",
    abandoned: "crit", silent: "crit", pinned: "high", "left-behind": "high",
    "old-promise": "med", stale: "med", unknown: "low", finished: "none", ok: "none"
  };
  var SIGNAL_NAMES = {
    S1: "marked abandoned", S2: "no stable release", S3: "repository archived",
    S4: "no push to the repository", S5: "release predates the target PHP",
    S6: "branch snapshot, not a release", S7: "pulls in flagged packages",
    S8: "the installed branch stopped", S9: "security advisories"
  };
  var DOCS = "https://lockrot.dev/verdicts/";
  // The report carries its own $schema, being the document --format=json writes.
  var SCHEMA_URL = REPORT["$schema"] || "https://lockrot.dev/schema/report-1.json";
  var SIGNAL_DOC = { S7: DOCS + "#transitive-exposure", S8: DOCS + "#left-behind", S9: DOCS + "#security-advisories" };
  var VERDICT_DEFS = {
    abandoned: "The package's Composer repository marks it abandoned, or its repository is archived on GitHub or GitLab.",
    silent: "No stable release for at least release-high-years and no repository push for at least push-high-years.",
    pinned: "The installed version is a branch snapshot \u2014 dev-master, a 2.x-dev alias, a #hash \u2014 or the package has no stable release at all.",
    "left-behind": "No stable release on the installed branch for release-warn-years, while a higher branch kept releasing. The package is alive; the branch you are on is not.",
    "old-promise": "The installed version was released before the target PHP's GA date, and its require.php constraint is open-ended for that target.",
    stale: "Old release or old push, but not old enough on both fronts for silent.",
    unknown: "No data could be obtained \u2014 not found in any configured Composer repository, or every lookup failed.",
    finished: "Matched the built-in or project allowlist. The package is complete by design, not neglected.",
    ok: "None of the above."
  };
  var SIGNAL_DEFS = {
    S1: "The Composer repository marks the package abandoned, sometimes naming a replacement.",
    S2: "Time since the last stable release, against release-warn-years / release-high-years.",
    S3: "The repository is archived \u2014 on GitHub, or on GitLab when the run has credentials there.",
    S4: "Time since the last push to any branch, against push-warn-years / push-high-years.",
    S5: "The installed release predates the target PHP's GA date and require.php has no upper bound.",
    S6: "The installed version is a branch snapshot, or the package has no stable release.",
    S7: "A direct requirement pulls in flagged transitive packages. Informational, never a verdict.",
    S8: "Time since the last stable release on the installed branch, counted only when a higher branch has released since.",
    S9: "Security advisories affecting the installed version. Never a verdict; raises the priority where no fix is coming."
  };
  var PRIORITY_BASE = {
    abandoned: "critical", silent: "critical",
    pinned: "high", "left-behind": "high", "old-promise": "high", stale: "medium"
  };

  /** Packagist page for a package, when the lock says it came from a Composer repository. */
  function packagistUrl(f) {
    var ex = DETAILS[f.package] || {};
    var fromRepo = ex.lock ? ex.lock.from_composer_repository !== false : true;
    return fromRepo ? "https://packagist.org/packages/" + f.package : null;
  }
  /** The repository the metadata points at, as a browsable URL. */
  /**
   * The link ReportDocument already scheme-checked. Checked again here because this is the value
   * that becomes an href, and a page should not trust its own payload to have been sanitised.
   */
  function repoUrl(f) {
    var link = (DETAILS[f.package] || {}).repository_link;
    return safeHref(link);
  }
  function kvSection(heading, rows) {
    return rows ? '<section class="sect"><h3>' + esc(heading) + '</h3><dl class="kv">' + rows + "</dl></section>" : "";
  }
  /**
   * The row for a package, found without building a selector out of its name. A name is data, and
   * CSS has metacharacters of its own: one backslash or bracket in it and querySelector throws,
   * which would take the keyboard with it.
   */
  function rowFor(name) {
    var rows = document.querySelectorAll("[data-pkg]");
    for (var i = 0; i < rows.length; i++) {
      if (rows[i].getAttribute("data-pkg") === name) return rows[i];
    }

    return null;
  }
  function repoHost(url) {
    var m = /^https?:\/\/([^/]+)/.exec(url || "");
    return m ? m[1].replace(/^www\./, "") : "repository";
  }
  function cveUrl(a) {
    return a.cve && /^CVE-/.test(a.cve) ? "https://nvd.nist.gov/vuln/detail/" + a.cve : null;
  }
  function outLink(url, text) {
    var href = safeHref(url);
    if (href === null) return '<span class="out">' + esc(text) + "</span>";

    return '<a class="out" href="' + esc(href) + '" target="_blank" rel="noopener noreferrer">' + esc(text) + "</a>";
  }
  /**
   * The verdicts that count as findings, as the tool itself defines them — `context.flagged_verdicts`
   * is written from Verdict::flagged(), so this page and lockrot's own table always agree on the
   * number. The fallback is for a document that carries no context, a bare `--format=json` one:
   * `unknown` is left out there too, because a package lockrot could not check is a note, not a
   * finding, and counting it would put the page one ahead of its own headline.
   */
  var FLAGGED_VERDICTS = RUN.flagged_verdicts ||
    ["abandoned", "silent", "pinned", "left-behind", "old-promise", "stale"];
  var FLAGGED = FINDINGS.filter(function (f) { return FLAGGED_VERDICTS.indexOf(f.verdict) !== -1; });

  /**
   * new / worsened / known, against the baseline file the run read. The report's own JSON carries
   * only the totals, so the state per finding is worked out here from the same file lockrot read.
   */
  /** Where each finding stands against the baseline, keyed by package, out of the findings. */
  var BASELINE = {};
  FINDINGS.forEach(function (f) { if (f.baseline) BASELINE[f.package] = f.baseline; });
  /**
   * Whether the standings are actually here. The `baseline` block gives the totals, and a report
   * written before 0.10.0 gives nothing but the totals — gating the filter on that block alone
   * would draw three counts that are all wrong.
   */
  var HAS_BASELINE = !!REPORT.baseline && Object.keys(BASELINE).length > 0;
  var SEVERITY_ORDER = ["abandoned", "silent", "pinned", "left-behind", "old-promise", "stale", "unknown"];
  /** new / known / worsened, as BaselineComparison decided it; null when the run had no baseline. */
  function baselineState(f) {
    var state = BASELINE[f.package];
    return state ? state.status : null;
  }
  var SEV_ORDER = ["critical", "high", "medium", "low", null];
  function sevTone(sev) {
    return sev === "critical" ? "crit" : sev === "high" ? "high" : sev === "medium" ? "med" : "low";
  }
  function advisoriesOf(f) {
    var s9 = (f.signals || []).filter(function (s) { return s.id === "S9"; })[0];
    return (s9 && s9.data && s9.data.advisories) || [];
  }
  var ALL_ADVISORIES = [];
  FINDINGS.forEach(function (f) {
    advisoriesOf(f).forEach(function (a) {
      var row = {};
      for (var k in a) row[k] = a[k];
      row._pkg = f.package;
      row._version = f.version;
      row._verdict = f.verdict;
      ALL_ADVISORIES.push(row);
    });
  });
  var ADV_PACKAGES = FINDINGS.filter(function (f) { return advisoriesOf(f).length; });
  /** Distinct releases that clear advisories, cheapest move first. */
  function fixLadder(f) {
    var by = {};
    advisoriesOf(f).forEach(function (a) {
      var key = a.fixed_by || "\u0000none";
      by[key] = by[key] || { version: a.fixed_by, onBranch: a.fixed_on_branch, n: 0 };
      by[key].n++;
    });
    return Object.keys(by).map(function (k) { return by[k]; }).sort(function (x, y) {
      if (x.onBranch !== y.onBranch) return x.onBranch ? -1 : 1;
      return y.n - x.n;
    });
  }

  function evidenceOf(f) {
    var e = f.evidence;
    if (!e) return "";
    return Array.isArray(e) ? e.join(" \u00b7 ") : String(e);
  }

  var state = { view: "findings", q: "", pkg: null, sort: "verdict", sortDesc: false, prio: {}, verdict: {}, scope: {}, signal: {}, sev: {}, fix: {}, since: {} };
  var visible = [];
  var cursor = -1;

  /* ---------- helpers ---------- */
  function el(id) { return document.getElementById(id); }
  function tone(key) { return TONE[key] || "low"; }
  function pill(key, link) {
    var t = tone(key);
    var style = "color:var(--" + t + ");background:var(--" + t + "-soft);border-color:var(--" + t + ")";
    var title = VERDICT_DEFS[key] ? ' title="' + esc(VERDICT_DEFS[key]) + '"' : "";
    if (!link) return '<span class="pill" style="' + style + '"' + title + ">" + esc(key) + "</span>";
    return '<a class="pill" style="' + style + ';text-decoration:none" href="' + DOCS + '#the-nine-verdicts"' +
      ' target="_blank" rel="noopener noreferrer"' + title + ">" + esc(key) + "</a>";
  }
  function day(iso) { return LockrotLib.day(iso); }
  function years(iso) { return LockrotLib.years(iso, NOW); }
  function ageText(iso) { return LockrotLib.ageText(iso, NOW); }
  function plural(n, one, many) { return LockrotLib.plural(n, one, many); }
  function anySelected(obj) {
    for (var k in obj) { if (obj[k]) return true; }
    return false;
  }

  /* ---------- query ---------- */
  function parseQuery(raw) { return LockrotLib.parseQuery(raw); }
  function matches(f, terms) {
    if (terms.text.length) {
      var hay = (f.package + " " + f.version + " " + f.verdict + " " + evidenceOf(f)).toLowerCase();
      for (var i = 0; i < terms.text.length; i++) { if (hay.indexOf(terms.text[i]) === -1) return false; }
    }
    if (terms.verdict.length && terms.verdict.indexOf(f.verdict) === -1) return false;
    if (terms.priority.length && terms.priority.indexOf(f.priority) === -1) return false;
    if (terms.direct !== null && !!f.direct !== terms.direct) return false;
    if (terms.dev !== null && !!f.dev !== terms.dev) return false;
    if (terms.signal.length) {
      var ids = (f.signals || []).map(function (s) { return s.id; });
      for (var j = 0; j < terms.signal.length; j++) { if (ids.indexOf(terms.signal[j]) === -1) return false; }
    }
    var adv = advisoriesOf(f);
    if (terms.severity.length && !adv.some(function (a) { return terms.severity.indexOf(String(a.severity)) !== -1; })) return false;
    if (terms.cve.length && !adv.some(function (a) {
      return terms.cve.some(function (c) { return (a.cve || a.id || "").toUpperCase().indexOf(c) !== -1; });
    })) return false;
    if (anySelected(state.sev) && !adv.some(function (a) { return state.sev[a.severity || "unrated"]; })) return false;
    if (anySelected(state.fix)) {
      var shape = function (a) { return !a.fixed_by ? "none" : (a.fixed_on_branch ? "branch" : "move"); };
      if (!adv.some(function (a) { return state.fix[shape(a)]; })) return false;
    }
    if (anySelected(state.prio) && !state.prio[f.priority]) return false;
    if (anySelected(state.verdict) && !state.verdict[f.verdict]) return false;
    if (anySelected(state.signal)) {
      var has = (f.signals || []).some(function (s) { return state.signal[s.id]; });
      if (!has) return false;
    }
    if (anySelected(state.since) && !state.since[baselineState(f) || "known"]) return false;
    if (state.scope.direct && !f.direct) return false;
    if (state.scope.transitive && f.direct) return false;
    if (state.scope.prod && f.dev) return false;
    if (state.scope.dev && !f.dev) return false;
    return true;
  }

  /* ---------- ledger ---------- */
  function renderLedger() {
    var counts = REPORT.priorities || {};
    var flaggedTotal = FLAGGED.length;
    el("flaggedCount").textContent = flaggedTotal;
    el("totalCount").textContent = FINDINGS.length;

    var pBar = [], pLeg = [];
    PRIORITIES.forEach(function (p) {
      if (p === "none") return;
      var n = counts[p] || 0;
      if (n) pBar.push('<span style="flex:' + n + ';background:var(--' + tone(p) + ')" title="' + p + ": " + n + '"></span>');
      pLeg.push('<button type="button" data-filter="prio" data-key="' + p + '" data-on="false">' +
        '<i class="swatch" style="background:var(--' + tone(p) + ')"></i>' + p + ' <i class="c">' + n + "</i></button>");
    });
    el("prioBar").innerHTML = pBar.join("") || '<span style="flex:1;background:var(--none)"></span>';
    el("prioLegend").innerHTML = pLeg.join("");

    var vc = REPORT.counts || {}, vBar = [], vLeg = [];
    VERDICTS.forEach(function (v) {
      var n = vc[v] || 0;
      if (!n) return;
      vBar.push('<span style="flex:' + n + ';background:var(--' + tone(v) + ');opacity:' +
        (v === "ok" ? ".35" : "1") + '" title="' + v + ": " + n + '"></span>');
      vLeg.push('<button type="button" data-filter="verdict" data-key="' + v + '" data-on="false">' +
        '<i class="swatch" style="background:var(--' + tone(v) + ');opacity:' + (v === "ok" ? ".35" : "1") + '"></i>' +
        v + ' <i class="c">' + n + "</i></button>");
    });
    el("verdictBar").innerHTML = vBar.join("");
    el("verdictLegend").innerHTML = vLeg.join("");

    var sc = {};
    ALL_ADVISORIES.forEach(function (a) {
      var k = a.severity || "unrated";
      sc[k] = (sc[k] || 0) + 1;
    });
    el("advLabel").textContent = ALL_ADVISORIES.length === 0
      ? "No advisory affects this lock"
      : plural(ALL_ADVISORIES.length, "advisory", "advisories") + " on " + plural(ADV_PACKAGES.length, "package", "packages");
    var aBar = [], aLeg = [];
    ["critical", "high", "medium", "low", "unrated"].forEach(function (k) {
      var n = sc[k] || 0;
      if (!n) return;
      var t = sevTone(k === "unrated" ? null : k);
      aBar.push('<span style="flex:' + n + ';background:var(--' + t + ')" title="' + k + ": " + n + '"></span>');
      aLeg.push('<button type="button" data-filter="sev" data-key="' + k + '" data-on="false">' +
        '<i class="swatch" style="background:var(--' + t + ')"></i>' + k + ' <i class="c">' + n + "</i></button>");
    });
    el("advBar").innerHTML = aBar.join("") || '<span style="flex:1;background:var(--none)"></span>';
    el("advLegend").innerHTML = aLeg.join("") || '<span style="color:var(--muted)">no advisory affects this lock</span>';

    // textContent, not innerHTML: the worst package's name is the document's, and this block is
    // prose, not a filter. A document from before 0.11.0 carries no block and says so.
    var ly = REPORT.libyears;
    el("libyearsTotal").textContent = ly && ly.measured ? Number(ly.total).toFixed(1) : "\u2014";
    el("libyearsLine").textContent = LockrotLib.libyearsLine(ly) || "not in this document";
  }

  /* ---------- rail ---------- */
  /**
   * The population the current tab draws from. The rail counts against it, so "Direct 214" never
   * sits over a list of 47 rows again, and a filter that cannot apply to this tab is not offered.
   */
  function population() {
    if (state.view === "findings" || state.view === "radius") return FLAGGED;
    if (state.view === "advisories") return ADV_PACKAGES;
    if (state.view === "packages") return FINDINGS;
    return [];
  }

  function renderRail() {
    var rail = el("rail");
    var here = population();
    // Run data describes the run, not its packages: nothing on it can be filtered.
    if (!here.length) {
      rail.hidden = true;
      rail.innerHTML = "";
      return;
    }
    rail.hidden = false;

    var sigCount = {};
    here.forEach(function (f) {
      (f.signals || []).forEach(function (sig) { sigCount[sig.id] = (sigCount[sig.id] || 0) + 1; });
    });
    var scope = [
      ["direct", "Direct", here.filter(function (f) { return f.direct; }).length],
      ["transitive", "Transitive", here.filter(function (f) { return !f.direct; }).length],
      ["prod", "require", here.filter(function (f) { return !f.dev; }).length],
      ["dev", "require-dev", here.filter(function (f) { return f.dev; }).length]
    ];
    var html = "";

    // The baseline lives in the rail, not in the Findings list: matches() applies it on every tab,
    // so the control has to be reachable from every tab that it silently narrows.
    if (HAS_BASELINE) {
      var since = [
        ["new", "New", here.filter(function (f) { return baselineState(f) === "new"; }).length],
        ["worsened", "Worsened", here.filter(function (f) { return baselineState(f) === "worsened"; }).length],
        ["known", "Already accepted", here.filter(function (f) { return baselineState(f) !== "new" && baselineState(f) !== "worsened"; }).length]
      ];
      html += '<div class="rail-group"><span class="eyebrow">Since ' + esc(REPORT.baseline.path) + '</span><div class="opts">';
      since.forEach(function (row) {
        html += '<button class="opt" type="button" data-filter="since" data-key="' + row[0] + '" aria-pressed="' +
          (state.since[row[0]] ? "true" : "false") + '">' + row[1] + '<span class="c">' + row[2] + "</span></button>";
      });
      html += "</div></div>";
    }

    html += '<div class="rail-group"><span class="eyebrow">Scope</span><div class="opts">';
    scope.forEach(function (row) {
      html += '<button class="opt" type="button" data-filter="scope" data-key="' + row[0] + '" aria-pressed="' +
        (state.scope[row[0]] ? "true" : "false") + '">' + row[1] + '<span class="c">' + row[2] + "</span></button>";
    });
    html += "</div></div>";

    if (Object.keys(sigCount).length) {
      html += '<div class="rail-group"><span class="eyebrow">Signal</span><div class="opts">';
      Object.keys(sigCount).sort().forEach(function (id) {
        html += '<button class="opt" type="button" data-filter="signal" data-key="' + id + '" aria-pressed="' +
          (state.signal[id] ? "true" : "false") + '" title="' + esc(SIGNAL_DEFS[id] || "") + '">' +
          '<span class="mono">' + id + "</span> " + esc(SIGNAL_NAMES[id] || "") +
          '<span class="c">' + sigCount[id] + "</span></button>";
      });
      html += "</div></div>";
    }

    var shapes = { branch: 0, move: 0, none: 0 };
    var advisories = 0;
    here.forEach(function (f) {
      advisoriesOf(f).forEach(function (a) {
        advisories++;
        shapes[!a.fixed_by ? "none" : (a.fixed_on_branch ? "branch" : "move")]++;
      });
    });
    if (advisories) {
      html += '<div class="rail-group"><span class="eyebrow">What the fix costs</span><div class="opts">';
      [["branch", "A release on this branch"], ["move", "Moving to another branch"], ["none", "No fix listed"]].forEach(function (pair) {
        if (!shapes[pair[0]]) return;
        html += '<button class="opt" type="button" data-filter="fix" data-key="' + pair[0] + '" aria-pressed="' +
          (state.fix[pair[0]] ? "true" : "false") + '">' + pair[1] + '<span class="c">' + shapes[pair[0]] + "</span></button>";
      });
      html += "</div></div>";
    }
    rail.innerHTML = html;
  }

  /* ---------- views ---------- */
  function signalLine(sig) {
    var cls = sig.level === "high" ? " is-high" : (sig.level === "warn" ? " is-warn" : "");
    var doc = SIGNAL_DOC[sig.id] || (DOCS + "#the-signals");
    return '<span class="sig-line' + cls + '">' +
      '<a class="sid" href="' + doc + '" target="_blank" rel="noopener noreferrer" title="' +
        esc(SIGNAL_DEFS[sig.id] || "") + '">' + esc(sig.id) + "</a>" +
      "<span>" + esc(sig.summary) + "</span></span>";
  }

  function rowHtml(f, idx) {
    var t = tone(f.priority === "none" ? f.verdict : f.priority);
    var tags = [];
    tags.push('<span class="tag" title="' + (f.direct ? "required by this project's composer.json" : "installed because something else requires it") + '">' +
      (f.direct ? "direct" : "transitive") + "</span>");
    if (f.dev) tags.push('<span class="tag" title="installed only for development">require-dev</span>');
    var adv = advisoriesOf(f);
    if (adv.length) {
      var worst = SEV_ORDER.filter(function (sv) {
        return adv.some(function (a) { return a.severity === sv; });
      })[0] || null;
      tags.unshift('<span class="cve-tag" style="color:var(--' + sevTone(worst) + ');border-color:var(--' + sevTone(worst) +
        ');background:var(--' + sevTone(worst) + '-soft)">' + adv.length + " advisor" + (adv.length > 1 ? "ies" : "y") + "</span>");
    }
    var bstate = baselineState(f);
    if (bstate === "new" || bstate === "worsened") {
      var bt = bstate === "new" ? "crit" : "high";
      tags.unshift('<span class="state" style="color:var(--' + bt + ');border-color:var(--' + bt + ');background:var(--' + bt +
        '-soft)" title="' + (bstate === "new" ? "not in the baseline file" : "the baseline recorded a milder verdict") + '">' +
        bstate + "</span>");
    }

    var sigs = (f.signals || []);
    var shown = sigs.slice(0, 3).map(signalLine).join("");
    var rest = sigs.length - 3;
    var body = sigs.length
      ? '<span class="sig-lines">' + shown + (rest > 0 ? '<span class="more-sig">+ ' + rest + " more signal" + (rest > 1 ? "s" : "") + ", open the package</span>" : "") + "</span>"
      : '<span class="ev">' + esc(evidenceOf(f)) + "</span>";

    return '<div class="row" data-idx="' + idx + '" data-pkg="' + esc(f.package) + '" role="button" tabindex="0"' +
      (state.pkg === f.package ? ' aria-current="true"' : "") + ">" +
      '<span class="stripe" style="background:var(--' + t + ')"></span>' +
      '<span class="body">' +
        '<span class="line1">' + pill(f.verdict) +
          '<span class="pkg">' + esc(f.package) + "</span>" +
          '<span class="ver mono">' + esc(f.version) + "</span>" +
          '<span class="tags">' + tags.join("") + "</span></span>" +
        body +
      "</span></div>";
  }

  function viewFindings(terms) {
    visible = FLAGGED.filter(function (f) { return matches(f, terms); });
    var head = "";
    var quiet = FINDINGS.filter(function (f) {
      return advisoriesOf(f).length && (f.verdict === "ok" || f.verdict === "finished");
    });
    if (quiet.length) {
      head += '<div class="note">' + quiet.length + " package" + (quiet.length > 1 ? "s carry" : " carries") +
        " a security advisory but no rot verdict, so " + (quiet.length > 1 ? "they are" : "it is") +
        " not in this list: " +
        quiet.map(function (f) {
          return '<button class="opt" type="button" data-open="' + esc(f.package) + '" style="padding:0 3px">' +
            esc(f.package) + "</button>";
        }).join(" ") +
        ' <button class="icon-btn" type="button" data-goto="advisories">See the advisories</button></div>';
    }
    if (!visible.length) return head + emptyState();
    var byPrio = {};
    visible.forEach(function (f) { (byPrio[f.priority] = byPrio[f.priority] || []).push(f); });
    var out = "", idx = 0;
    PRIORITIES.forEach(function (p) {
      var list = byPrio[p];
      if (!list) return;
      out += '<section class="group"><div class="group-head"><h2 style="color:var(--' + tone(p) + ')">' + p +
        '</h2><span class="mono" style="color:var(--muted);font-size:12px">' + list.length + " package" +
        (list.length > 1 ? "s" : "") + "</span></div><div class=\"rows\">";
      list.forEach(function (f) { out += rowHtml(f, idx++); });
      out += "</div></section>";
    });
    return head + out;
  }

  function advRow(a) {
    var t = sevTone(a.severity);
    var fix = a.fixed_by
      ? (a.fixed_on_branch ? "fixed by " + a.fixed_by + " on this branch" : "fixed only by " + a.fixed_by)
      : "no fix listed";
    return '<div class="adv"><span class="sev" style="color:var(--' + t + ');border-color:var(--' + t +
      ');background:var(--' + t + '-soft)">' + esc(a.severity || "unrated") + "</span>" +
      '<span class="t">' + esc(a.title || a.id) + "</span>" +
      '<span class="m">' +
        '<button class="opt" type="button" data-open="' + esc(a._pkg) + '" style="padding:0;font-size:11.5px">' +
          esc(a._pkg) + " " + esc(a._version) + "</button>" +
        (cveUrl(a) ? outLink(cveUrl(a), a.cve) : '<span class="mono">' + esc(a.cve || a.id) + "</span>") +
        "<span>" + esc(fix) + "</span>" +
        "<span>affects " + esc(a.affected_versions || "?") + "</span>" +
        "<span>reported " + day(a.reported_at) + (a.reported_at ? ", open " + ageText(a.reported_at).replace(" ago", "") : "") + "</span>" +
        (a.link ? outLink(a.link, "advisory") : "") +
      "</span></div>";
  }

  /**
   * Whether one advisory passes the filters that are about advisories. Filtering the package and
   * then drawing all of its advisories showed four low ones next to the critical that was asked
   * for, and put rows in fix-shape groups nobody had selected.
   */
  function advisoryMatches(a, terms) {
    var severity = a.severity || "unrated";
    if (terms.severity.length && terms.severity.indexOf(String(a.severity)) === -1) return false;
    if (anySelected(state.sev) && !state.sev[severity]) return false;
    var shape = !a.fixed_by ? "none" : (a.fixed_on_branch ? "branch" : "move");
    if (anySelected(state.fix) && !state.fix[shape]) return false;
    if (terms.cve.length) {
      var id = (a.cve || a.id || "").toUpperCase();
      var hit = terms.cve.some(function (c) { return id.indexOf(c) !== -1; });
      if (!hit) return false;
    }
    return true;
  }

  function viewAdvisories(terms) {
    visible = FINDINGS.filter(function (f) { return advisoriesOf(f).length && matches(f, terms); });
    var keep = {};
    visible.forEach(function (f) { keep[f.package] = true; });
    var rows = ALL_ADVISORIES.filter(function (a) { return keep[a._pkg] && advisoryMatches(a, terms); });
    if (!rows.length) return emptyState();

    rows.sort(function (x, y) {
      var d = SEV_ORDER.indexOf(x.severity) - SEV_ORDER.indexOf(y.severity);
      if (d) return d;
      return String(y.reported_at || "").localeCompare(String(x.reported_at || ""));
    });
    var groups = [
      ["branch", "A release on the branch you are on", "The cheapest move: a patch or minor bump, no migration."],
      ["move", "Only a move to another branch", "The fix never landed on your branch. This is an upgrade, not a bump."],
      ["none", "No fix listed", "Nothing published clears it. Replacement or mitigation."]
    ];
    var out = "";
    groups.forEach(function (g) {
      var list = rows.filter(function (a) {
        return (!a.fixed_by ? "none" : (a.fixed_on_branch ? "branch" : "move")) === g[0];
      });
      if (!list.length) return;
      out += '<section class="advgroup"><header><h2>' + g[1] + "</h2>" +
        '<span class="mono" style="font-size:12px;color:var(--muted)">' + list.length + "</span>" +
        "<p>" + g[2] + "</p></header>" + list.map(advRow).join("") + "</section>";
    });
    return out;
  }

  var SORTS = {
    package: function (f) { return f.package; },
    version: function (f) { return f.version; },
    libyears: LockrotLib.libyearsSortKey,
    verdict: function (f) { return SEVERITY_ORDER.indexOf(f.verdict) === -1 ? 99 : SEVERITY_ORDER.indexOf(f.verdict); },
    priority: function (f) { return PRIORITIES.indexOf(f.priority); },
    reached: function (f) { return (f.direct ? "0" : "1") + (f.dev ? "1" : "0"); },
    signals: function (f) { return -(f.signals || []).length; },
    data: function (f) { return f.data_date || ""; }
  };
  function viewPackages(terms) {
    visible = FINDINGS.filter(function (f) { return matches(f, terms); });
    if (!visible.length) return emptyState();
    var key = SORTS[state.sort] ? state.sort : "verdict";
    visible = visible.slice().sort(function (a, b) {
      var x = SORTS[key](a), y = SORTS[key](b);
      var d = x < y ? -1 : (x > y ? 1 : 0);
      return state.sortDesc ? -d : d;
    });
    var rows = visible.map(function (f, i) {
      var last = f.data_date;
      return '<tr data-idx="' + i + '" data-pkg="' + esc(f.package) + '">' +
        "<td>" + (packagistUrl(f) ? '<a class="lnk" href="' + esc(packagistUrl(f)) + '" target="_blank" rel="noopener noreferrer">' + esc(f.package) + "</a>" : esc(f.package)) + "</td>" +
        '<td class="num">' + esc(f.version) + "</td>" +
        '<td class="num">' + (f.libyears == null ? '<span style="color:var(--muted)">\u2014</span>' : Number(f.libyears).toFixed(1)) + "</td>" +
        "<td>" + pill(f.verdict) + "</td>" +
        "<td>" + (f.priority === "none" ? '<span style="color:var(--muted)">—</span>' : pill(f.priority)) + "</td>" +
        "<td>" + (f.direct ? "direct" : "transitive") + (f.dev ? " \u00b7 dev" : "") + "</td>" +
        '<td class="num">' + ((f.signals || []).map(function (s) { return s.id; }).join(" ") || "—") + "</td>" +
        '<td class="num">' + day(last) + "</td></tr>";
    }).join("");
    var head = [["package", "Package"], ["version", "Version"], ["libyears", "Libyears"], ["verdict", "Verdict"], ["priority", "Priority"],
      ["reached", "Reached"], ["signals", "Signals"], ["data", "Data as of"]].map(function (c) {
      var on = (SORTS[state.sort] ? state.sort : "verdict") === c[0];
      return '<th aria-sort="' + (on ? (state.sortDesc ? "descending" : "ascending") : "none") +
        '"><button type="button" data-sort="' + c[0] +
        '" style="background:none;border:0;padding:0;cursor:pointer;font:inherit;letter-spacing:inherit;text-transform:inherit;color:' +
        (on ? "var(--ink)" : "inherit") + '">' + c[1] + (on ? (state.sortDesc ? " \u2193" : " \u2191") : "") + "</button></th>";
    }).join("");
    return '<div class="tablewrap"><table><thead><tr>' + head + "</tr></thead><tbody>" + rows + "</tbody></table></div>";
  }

  function viewRadius(terms) {
    var kept = FLAGGED.filter(function (f) { return matches(f, terms); });
    var keptNames = {};
    kept.forEach(function (f) { keptNames[f.package] = true; });
    visible = kept;
    var exposure = (REPORT.exposure || []).slice()
      .map(function (e) {
        var pulled = kept.filter(function (f) {
          return (f.chain || []).indexOf(e.package) !== -1 && f.package !== e.package;
        });
        return { package: e.package, flagged: pulled.length + (keptNames[e.package] ? 1 : 0), pulled: pulled };
      })
      .filter(function (e) { return e.flagged > 0; })
      .sort(function (a, b) { return b.flagged - a.flagged; });
    if (!exposure.length) return '<div class="empty">No direct requirement drags a flagged package in.</div>';
    var max = exposure[0].flagged || 1;
    var cards = exposure.map(function (e) {
      var pulled = e.pulled;
      var names = pulled.map(function (f) {
        return '<div style="display:flex;gap:7px;align-items:baseline"><span class="mono" style="font-size:11px;color:var(--muted)">→</span>' +
          '<button class="opt" type="button" data-open="' + esc(f.package) + '" style="padding:1px 4px">' +
          esc(f.package) + "</button>" + pill(f.verdict) + "</div>";
      }).join("");
      return '<article class="card"><h3>' + esc(e.package) + "</h3>" +
        '<div class="meter"><i style="width:' + Math.round(100 * e.flagged / max) + '%"></i></div>' +
        '<span class="eyebrow">' + e.flagged + " flagged package" + (e.flagged > 1 ? "s" : "") + " underneath</span>" +
        (names || '<span style="color:var(--muted);font-size:12px">flagged itself</span>') + "</article>";
    }).join("");
    return '<p class="hint" style="margin-top:0">Direct requirements ranked by how much rot each one brings with it. ' +
      "Fixing the parent is often cheaper than chasing the child.</p>" + '<div class="cards">' + cards + "</div>";
  }

  function viewRun() {
    visible = [];
    var t = RUN.thresholds || {};
    var notes = (REPORT.notes || []).map(function (n) {
      var doc = /token|activity|repository/.test(n) ? "https://lockrot.dev/internals/" : "https://lockrot.dev/configuration/";
      return '<div class="note">' + esc(n) + ' <span style="white-space:nowrap">' + outLink(doc, "what this means") + "</span></div>";
    }).join("");
    var kv = [
      ["lockrot", (TOOL.version || "?") + " (report schema " + (TOOL.schema || "?") + ")"],
      ["generated", REPORT.generated_at],
      ["packages checked", REPORT.packages_checked],
      ["include dev", String(REPORT.include_dev)],
      ["fail-on", String(RUN.fail_on || "none")],
      ["oldest activity cache", REPORT.activity_cache_oldest_at || "—"],
      ["network failures", String(REPORT.network_failures)],
      ["not from a Composer repository", String(REPORT.not_from_composer_repository)],
      ["libyears", LockrotLib.libyearsLine(REPORT.libyears) || "not in this document"],
      ["baseline", REPORT.baseline ? JSON.stringify(REPORT.baseline) : "none"]
    ].map(function (p) { return "<dt>" + esc(p[0]) + "</dt><dd>" + esc(p[1]) + "</dd>"; }).join("");
    var th = Object.keys(t).map(function (k) { return "<dt>" + esc(k) + "</dt><dd>" + esc(t[k]) + " years</dd>"; }).join("");
    return '<div style="display:flex;flex-direction:column;gap:18px">' +
      (notes ? '<section class="sect"><h3>What this run could not see</h3>' + notes + "</section>" : "") +
      '<section class="sect"><h3>Thresholds in force</h3><div class="tablewrap" style="padding:12px 14px"><dl class="kv">' + th + "</dl></div></section>" +
      '<section class="sect"><h3>Run</h3><div class="tablewrap" style="padding:12px 14px"><dl class="kv">' + kv + "</dl></div>" +
      '<p style="margin:6px 0 0;font-size:12px;color:var(--muted)">This document validates against ' +
        outLink(SCHEMA_URL, SCHEMA_URL) +
        ". " + outLink("https://lockrot.dev/internals/", "How lockrot fetches and caches metadata") + "</p></section>" +
      "</div>";
  }

  function emptyState() {
    return '<div class="empty">Nothing matches this filter. <button class="icon-btn" id="emptyClear" type="button">Clear filters</button></div>';
  }

  /* ---------- branch timeline ---------- */
  function timeline(meta, installedVersion) {
    var branches = (meta && meta.branches) || [];
    var dated = branches.filter(function (b) { return b.highest_released || b.newest_dated_released; });
    if (dated.length < 2) return "";
    var times = dated.map(function (b) { return new Date(b.highest_released || b.newest_dated_released).getTime(); });
    var min = Math.min.apply(null, times);
    var max = NOW.getTime();
    var span = Math.max(max - min, 1);
    function pct(t) { return 4 + 88 * (t - min) / span; }

    var lanes = dated.slice().sort(function (a, b) {
      return new Date(b.highest_released || b.newest_dated_released) - new Date(a.highest_released || a.newest_dated_released);
    });
    var newest = lanes[0];

    var rows = lanes.map(function (b) {
      var iso = b.highest_released || b.newest_dated_released;
      var x = pct(new Date(iso).getTime());
      var cls = "lane" + (b.installed ? " is-installed" : "") + (b === newest && !b.installed ? " is-newest" : "");
      var labLeft = x > 62;
      var lab = '<span class="lab" style="' + (labLeft ? "right:" + (100 - x + 2) + "%" : "left:" + (x + 2) + "%") + '">' +
        esc(b.highest) + " \u00b7 " + day(iso) + "</span>";
      return '<div class="' + cls + '"><span class="bl">' + esc(b.branch) + "</span>" +
        '<span class="track"><span class="mark" style="left:' + x + '%"></span>' + lab + "</span></div>";
    }).join("");

    var startYear = new Date(min).getUTCFullYear();
    var endYear = NOW.getUTCFullYear();
    var ticks = "";
    var step = (endYear - startYear) > 8 ? 3 : ((endYear - startYear) > 4 ? 2 : 1);
    for (var y = startYear; y <= endYear; y += step) {
      var t = Date.UTC(y, 0, 1);
      if (t < min) continue;
      ticks += '<span style="left:' + pct(t) + '%">' + y + "</span>";
    }
    return '<div class="tl"><div class="tl-axis">' + ticks + "</div>" + rows + "</div>" +
      '<div class="tl-note">' +
      '<i><span class="swatch" style="background:var(--crit)"></span>you are on ' + esc(installedVersion) + "</i>" +
      '<i><span class="swatch" style="background:var(--none)"></span>branch still releasing</i>' +
      "<i>one dot = that branch's newest dated release</i></div>";
  }

  /* ---------- detail ---------- */
  function signalHtml(s) {
    var data = s.data || {};
    var rows = Object.keys(data).map(function (k) {
      var v = data[k];
      if (v && typeof v === "object") v = JSON.stringify(v);
      return "<dt>" + esc(k) + "</dt><dd>" + esc(v === null ? "null" : v) + "</dd>";
    }).join("");
    var t = s.level === "high" ? "crit" : (s.level === "warn" ? "high" : "low");
    var doc = SIGNAL_DOC[s.id] || (DOCS + "#the-signals");
    return '<details class="signal"><summary><a class="sid" style="color:var(--' + t + ');text-decoration:none" href="' +
      doc + '" target="_blank" rel="noopener noreferrer" title="' + esc(SIGNAL_DEFS[s.id] || "") + '">' + esc(s.id) +
      '</a><span class="ssum">' + esc(s.summary) + "</span></summary>" +
      '<div class="sdata"><dl class="kv">' + (rows || "<dt>—</dt><dd>no data</dd>") + "</dl></div></details>";
  }

  /** Rebuilds the ladder Priority::of() walks, so the number is not a black box. */
  function priorityWhy(f) {
    if (f.priority === "none" || !PRIORITY_BASE[f.verdict]) return "";
    var steps = ['<span class="step">' + esc(f.verdict) + " starts at " + PRIORITY_BASE[f.verdict] + "</span>"];
    if (!f.direct) steps.push('<span class="step">nothing requires it directly, one step down</span>');
    if (f.dev) steps.push('<span class="step">development only, one step down</span>');
    if (/no fix expected/.test(evidenceOf(f))) steps.push('<span class="step">an advisory no release will fix, one step up</span>');
    return steps.join('<span aria-hidden="true">&rarr;</span>') + "<b>" + esc(f.priority) + "</b>";
  }

  function renderDetail() {
    var box = el("detail");
    if (!state.pkg) { box.hidden = true; box.innerHTML = ""; return; }
    var f = FINDINGS.filter(function (x) { return x.package === state.pkg; })[0];
    if (!f) { box.hidden = true; return; }
    var ex = DETAILS[f.package] || {};
    var meta = ex.metadata || {};
    var lock = ex.lock || {};

    var s8 = (f.signals || []).filter(function (s) { return s.id === "S8"; })[0];
    var suggestion = s8 && s8.data && s8.data.suggested_constraint;
    var command = suggestion ? installCommand(f.package, suggestion) : null;
    var pk = packagistUrl(f);
    var rp = repoUrl(f);
    var replacement = meta.replacement || null;
    var why = priorityWhy(f);
    var bstate = baselineState(f);

    var chain = (f.chain || []);
    var chainHtml = f.direct
      ? '<span class="mono">composer.json</span> → <span class="mono">' + esc(f.package) + "</span>"
      : chain.concat([f.package]).map(function (p) { return '<span class="mono">' + esc(p) + "</span>"; }).join(" → ");

    var lockRows = kvRows([
      ["installed", esc(f.version)],
      ["php constraint", lock.php ? esc(lock.php) : null],
      ["released", lock.released ? esc(day(lock.released) + " \u00b7 " + ageText(lock.released)) : null],
      ["repository", rp
        ? '<a class="lnk" href="' + esc(rp) + '" target="_blank" rel="noopener noreferrer">' + esc(rp) + "</a>"
        : (lock.repository || meta.repository ? esc(lock.repository || meta.repository) : null)],
      ["type", lock.type || meta.type ? esc(lock.type || meta.type) : null]
    ]);
    var provenanceRows = kvRows([
      ["metadata", esc(day(meta.data_date || f.data_date))],
      ["releases listed", meta.releases_listed === undefined ? null : esc(meta.releases_listed)],
      ["last stable", meta.last_stable_version
        ? esc(meta.last_stable_version) + " \u00b7 " + esc(day(meta.last_stable_release)) : null]
    ]);

    var tl = timeline(meta, f.version);

    box.hidden = false;
    box.innerHTML =
      '<div class="detail-head"><div class="top">' +
        "<div style=\"flex:1;min-width:0\"><h2>" + esc(f.package) + "</h2>" +
        '<div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:6px">' + pill(f.verdict, true) +
          (f.priority === "none" ? "" : pill(f.priority)) +
          '<span class="tag">' + (f.direct ? "direct" : "transitive") + "</span>" +
          (f.dev ? '<span class="tag">require-dev</span>' : "") + "</div>" +
        '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:7px">' +
          (pk ? outLink(pk, "packagist") : "") +
          (rp ? outLink(rp, repoHost(rp)) : "") +
          (replacement ? outLink("https://packagist.org/packages/" + replacement, "replacement: " + replacement) : "") +
        "</div></div>" +
        '<button class="icon-btn" type="button" id="closeDetail">Close</button>' +
      "</div></div>" +
      '<div class="detail-body">' +
        (bstate ? '<section class="sect"><h3>Against the baseline</h3><p style="margin:0;font-size:13px;color:var(--ink-2)">' +
          (bstate === "new" ? "Not in " + esc(REPORT.baseline ? REPORT.baseline.path : "the baseline") + ". This one is new since it was written."
            : bstate === "worsened" ? "The baseline recorded <span class=\"mono\">" + esc((BASELINE[f.package] || {}).previous_verdict || "a milder verdict") +
              "</span>. It has got worse since."
            : "Already accepted in " + esc(REPORT.baseline ? REPORT.baseline.path : "the baseline") + ". It does not fail the build.") +
          "</p></section>" : "") +
        (why ? '<section class="sect"><h3>Why this is ' + esc(f.priority) + '</h3><div class="why">' + why + "</div></section>" : "") +
        (advisoriesOf(f).length ? (function () {
          var adv = advisoriesOf(f);
          var ladder = fixLadder(f).map(function (r) {
            return '<div class="rung' + (r.onBranch ? " here" : "") + '">' +
              '<span class="v">' + esc(r.version || "no release") + "</span>" +
              '<span class="bar2"><i style="width:' + Math.round(100 * r.n / adv.length) + '%"></i></span>' +
              '<span class="n">clears ' + r.n + " of " + adv.length + (r.onBranch ? " \u00b7 this branch" : "") + "</span></div>";
          }).join("");
          var list = adv.slice().sort(function (x, y) {
            return SEV_ORDER.indexOf(x.severity) - SEV_ORDER.indexOf(y.severity);
          }).map(function (a) {
            var t = sevTone(a.severity);
            return '<div class="adv" style="padding:8px 0"><span class="sev" style="color:var(--' + t +
              ');border-color:var(--' + t + ');background:var(--' + t + '-soft)">' + esc(a.severity || "unrated") + "</span>" +
              '<span class="t" style="font-size:12.5px">' + esc(a.title || a.id) + "</span>" +
              '<span class="m">' +
              (cveUrl(a) ? outLink(cveUrl(a), a.cve) : '<span class="mono">' + esc(a.cve || a.id) + "</span>") +
              "<span>" + (a.fixed_by ? "fixed by " + esc(a.fixed_by) : "no fix listed") + "</span>" +
              "<span>reported " + day(a.reported_at) + "</span>" +
              (a.link ? outLink(a.link, "advisory") : "") +
              "</span></div>";
          }).join("");
          return '<section class="sect"><h3>' + adv.length + " security advisor" + (adv.length > 1 ? "ies" : "y") +
            '</h3><div class="ladder">' + ladder + "</div>" +
            '<details class="signal" style="background:transparent"><summary><span class="ssum">Every advisory</span></summary>' +
            '<div class="sdata" style="padding-left:11px">' + list + "</div></details></section>";
        })() : "") +
        (command ?
          '<div class="action"><span class="eyebrow" style="color:var(--accent-ink)">Follow the upstream</span>' +
          '<div class="cmd"><span>' + esc(command) + "</span>" +
          '<button class="copy" type="button" data-copy="' + esc(command) + '">Copy</button></div>' +
          "</div>" : "") +
        (tl ? '<section class="sect"><h3>Release branches</h3>' + tl + "</section>" : "") +
        '<section class="sect"><h3>Signals &mdash; what was observed</h3>' + (f.signals || []).map(signalHtml).join("") +
          (!(f.signals || []).length ? '<p style="margin:0;color:var(--muted)">No signal fired. The verdict comes from what lockrot could not learn.</p>' : "") +
        "</section>" +
        '<section class="sect"><h3>How it is reached</h3><p style="margin:0;font-size:12.5px;word-break:break-word">' + chainHtml + "</p></section>" +
        kvSection("The lock entry", lockRows) +
        kvSection("Provenance", provenanceRows) +
      "</div>";
  }

  /* ---------- render ---------- */
  function render() {
    var terms = parseQuery(state.q);
    var root = el("viewRoot");
    if (state.view === "findings") root.innerHTML = viewFindings(terms);
    else if (state.view === "advisories") root.innerHTML = viewAdvisories(terms);
    else if (state.view === "packages") root.innerHTML = viewPackages(terms);
    else if (state.view === "radius") root.innerHTML = viewRadius(terms);
    else root.innerHTML = viewRun();

    Array.prototype.forEach.call(document.querySelectorAll(".tab"), function (t) {
      t.setAttribute("aria-selected", t.dataset.view === state.view ? "true" : "false");
    });
    el("tabFindings").textContent = FLAGGED.length;
    el("tabAdvisories").textContent = ALL_ADVISORIES.length;
    el("tabPackages").textContent = FINDINGS.length;
    el("tabRadius").textContent = (REPORT.exposure || []).length;
    el("tabRun").textContent = (REPORT.notes || []).length ? String(REPORT.notes.length) : "";

    Array.prototype.forEach.call(document.querySelectorAll("[data-filter]"), function (b) {
      var on = state[b.dataset.filter][b.dataset.key];
      if (b.classList.contains("opt")) b.setAttribute("aria-pressed", on ? "true" : "false");
      else b.dataset.on = on ? "true" : "false";
    });
    var active = FILTER_GROUPS.reduce(function (n, g) {
      return n + Object.keys(state[g]).filter(function (k) { return state[g][k]; }).length;
    }, 0) + (state.q ? 1 : 0);
    var line = "";
    if (state.view === "findings") line = visible.length + " of " + FLAGGED.length + " flagged packages";
    else if (state.view === "advisories") line = root.querySelectorAll(".advgroup .adv").length + " of " + ALL_ADVISORIES.length + " advisories";
    else if (state.view === "packages") line = visible.length + " of " + FINDINGS.length + " packages";
    else if (state.view === "radius") line = root.querySelectorAll(".card").length + " of " + (REPORT.exposure || []).length + " direct requirements";
    el("countLine").hidden = !line;
    el("countLine").innerHTML = esc(line) +
      (line && active ? ' <span style="color:var(--accent-ink)">' + active + " filter" + (active > 1 ? "s" : "") + " on</span>" : "");
    el("clearBtn").disabled = !active;
    el("clearBtn").style.opacity = active ? "1" : ".5";

    var filterable = population().length > 0;
    document.querySelector(".shell").classList.toggle("no-rail", !filterable);
    document.querySelector(".searchbar").hidden = !filterable;
    document.querySelector(".hint").hidden = !filterable;
    document.querySelector(".ledger").hidden = state.view === "run";

    renderRail();
    renderDetail();
    document.body.classList.toggle("detail-open", !!state.pkg && !WIDE);
    writeHash();
  }

  /**
   * Open a package's detail, or close it with null. Every route a reader can take goes through
   * here, which is what clears `pkgAuto`: from this point the selection is theirs, and the address
   * bar and a copied link both start naming it.
   */
  function select(pkg) {
    state.pkg = pkg;
    state.pkgAuto = false;
  }

  var FILTER_GROUPS = ["prio", "verdict", "scope", "signal", "sev", "fix", "since"];
  /** Whether this document is allowed to write its own address. False inside a sandboxed frame. */
  var URL_STATE = true;
  function writeHash() {
    if (!URL_STATE) return;
    var parts = [];
    if (state.view !== "findings") parts.push("view=" + state.view);
    if (state.q) parts.push("q=" + encodeURIComponent(state.q));
    FILTER_GROUPS.forEach(function (g) {
      var on = Object.keys(state[g]).filter(function (k) { return state[g][k]; });
      if (on.length) parts.push(g + "=" + encodeURIComponent(on.join(",")));
    });
    if (state.pkg && !state.pkgAuto) parts.push("pkg=" + encodeURIComponent(state.pkg));
    var h = parts.join("&");
    if (h === location.hash.replace(/^#/, "")) return;
    // A document with an opaque origin — a sandboxed frame, which is where a page rendering
    // somebody else's report belongs — refuses replaceState with a SecurityError. It is the last
    // thing render() does, so an unguarded throw leaves the page blank. There the view simply does
    // not live in the URL, and whatever embeds the frame owns the address instead.
    try {
      history.replaceState(null, "", h ? "#" + h : location.pathname);
    } catch (err) {
      URL_STATE = false;
    }
  }
  function readHash() {
    var h = location.hash.replace(/^#/, "");
    if (!h) return;
    h.split("&").forEach(function (p) {
      var i = p.indexOf("=");
      if (i < 0) return;
      var k = p.slice(0, i), v = decodeURIComponent(p.slice(i + 1));
      if (k === "view") state.view = v;
      if (k === "q") { state.q = v; el("q").value = v; }
      if (k === "pkg") select(v);
      if (FILTER_GROUPS.indexOf(k) !== -1) {
        v.split(",").forEach(function (key) { if (key) state[k][key] = true; });
      }
    });
  }

  /* ---------- events ---------- */
  document.addEventListener("click", function (e) {
    var tab = e.target.closest(".tab");
    if (tab) { state.view = tab.dataset.view; select(null); cursor = -1; render(); return; }

    var filter = e.target.closest("[data-filter]");
    if (filter) {
      var group = filter.dataset.filter;
      state[group][filter.dataset.key] = !state[group][filter.dataset.key];
      render();
      return;
    }
    var sorter = e.target.closest("[data-sort]");
    if (sorter) {
      if (state.sort === sorter.dataset.sort) state.sortDesc = !state.sortDesc;
      else { state.sort = sorter.dataset.sort; state.sortDesc = false; }
      render();
      return;
    }
    var goto = e.target.closest("[data-goto]");
    if (goto) { state.view = goto.dataset.goto; cursor = -1; render(); return; }
    var open = e.target.closest("[data-open]");
    if (open) { select(open.dataset.open); render(); return; }

    var copy = e.target.closest("[data-copy]");
    if (copy) {
      var text = copy.dataset.copy;
      var done = function (ok) {
        copy.textContent = ok ? "Copied" : "Select it and copy";
        setTimeout(function () { copy.textContent = "Copy"; }, ok ? 1400 : 2600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
      } else {
        done(false);
      }
      return;
    }
    if (e.target.closest("#legendBtn")) { openLegend(); return; }
    if (e.target.closest("#legendClose")) { closeLegend(); return; }
    if (e.target.closest("#closeDetail")) { select(null); render(); lastRow(); return; }
    if (e.target.closest("#clearBtn") || e.target.closest("#emptyClear")) {
      state.q = ""; state.prio = {}; state.verdict = {}; state.scope = {}; state.signal = {}; state.sev = {}; state.fix = {}; state.since = {};
      el("q").value = "";
      render();
      return;
    }
    var row = e.target.closest(".row, tbody tr");
    if (row && e.target.closest("a")) return;
    if (row && row.dataset.pkg) {
      select(state.pkg === row.dataset.pkg ? null : row.dataset.pkg);
      cursor = parseInt(row.dataset.idx, 10);
      render();
    }
  });

  el("q").addEventListener("input", function () { state.q = this.value; cursor = -1; render(); });

  el("themeBtn").addEventListener("click", function () {
    var dark = document.documentElement.getAttribute("data-theme") === "dark";
    var next = dark ? "light" : "dark";
    document.documentElement.setAttribute("data-theme", next);
    this.textContent = next === "dark" ? "Light" : "Dark";
    try { localStorage.setItem("lockrot-theme", next); } catch (err) { /* private mode */ }
  });

  /** Puts focus back on the row the detail was opened from. */
  function lastRow() {
    if (cursor < 0 || !visible[cursor]) return;
    var node = rowFor(visible[cursor].package);
    if (node && node.focus) node.focus();
  }

  document.addEventListener("keydown", function (e) {
    if (e.key === "Enter" || e.key === " ") {
      var row = e.target.closest && e.target.closest(".row, tbody tr");
      if (row && row.dataset.pkg) {
        e.preventDefault();
        select(state.pkg === row.dataset.pkg ? null : row.dataset.pkg);
        cursor = parseInt(row.dataset.idx, 10);
        render();
        return;
      }
    }
    if (e.key === "?" && document.activeElement !== el("q")) { e.preventDefault(); openLegend(); return; }
    if (e.key === "/" && document.activeElement !== el("q")) { e.preventDefault(); el("q").focus(); return; }
    if (e.key === "Escape") {
      if (el("legend").open) { closeLegend(); return; }
      if (state.pkg) { select(null); render(); lastRow(); } else el("q").blur();
      return;
    }
    if (document.activeElement === el("q")) return;
    if (e.key === "j" || e.key === "k") {
      if (!visible.length) return;
      e.preventDefault();
      cursor = Math.max(0, Math.min(visible.length - 1, cursor + (e.key === "j" ? 1 : -1)));
      select(visible[cursor].package);
      render();
      var node = rowFor(visible[cursor].package);
      if (node) node.scrollIntoView({ block: "nearest" });
    }
  });

  /* ---------- glossary ---------- */
  function fillLegend() {
    var vc = REPORT.counts || {};
    el("verdictDefs").innerHTML = VERDICTS.map(function (v) {
      var n = vc[v] || 0;
      return '<dt style="color:var(--' + tone(v) + ')">' + esc(v) +
        (n ? ' <span style="color:var(--muted)">' + n + "</span>" : "") + "</dt><dd>" + esc(VERDICT_DEFS[v]) + "</dd>";
    }).join("");
    el("signalDefs").innerHTML = Object.keys(SIGNAL_DEFS).map(function (id) {
      var doc = SIGNAL_DOC[id];
      return "<dt>" + esc(id) + " " + (doc ? outLink(doc, SIGNAL_NAMES[id]) : '<span style="color:var(--muted);font-family:var(--sans);font-size:12px">' +
        esc(SIGNAL_NAMES[id]) + "</span>") + "</dt><dd>" + esc(SIGNAL_DEFS[id]) + "</dd>";
    }).join("");
  }
  function openLegend() {
    var d = el("legend");
    // showModal() exists and still throws where modals are not allowed — inside a sandboxed frame,
    // which is where a page rendering someone else's document belongs. Feature detection alone
    // would leave the glossary unreachable there, so the failure falls through to the attribute.
    try {
      if (d.showModal) { d.showModal(); return; }
    } catch (err) { /* modals are not allowed here */ }
    d.setAttribute("open", "open");
  }
  function closeLegend() {
    var d = el("legend");
    if (d.close) d.close(); else d.removeAttribute("open");
  }

  /* ---------- boot ---------- */
  try {
    var saved = localStorage.getItem("lockrot-theme");
    if (saved) document.documentElement.setAttribute("data-theme", saved);
  } catch (err) { /* private mode */ }
  el("themeBtn").textContent = document.documentElement.getAttribute("data-theme") === "dark" ? "Light" : "Dark";

  el("mVersion").textContent = TOOL.version || "\u2014";
  el("fVersion").textContent = TOOL.version || "\u2014";
  el("fDate").textContent = day(REPORT.generated_at);
  el("mData").textContent = day(REPORT.generated_at);
  el("mTarget").textContent = RUN.target_php || "\u2014";
  // The project names itself; the lock is called composer.lock everywhere, so it is the fallback.
  el("projectName").textContent = RUN.project || RUN.lock_file || "composer.lock";

  readHash();
  var WIDE = !window.matchMedia || window.matchMedia("(min-width: 1181px)").matches;
  // On a wide screen the detail pane would otherwise open empty, so the first finding is shown.
  // Nobody asked for it, so it stays out of the address bar and out of a copied link: a link that
  // says pkg= reads as "look at this one", and this one was picked by the page.
  if (!state.pkg && WIDE && FLAGGED.length) { state.pkg = FLAGGED[0].package; state.pkgAuto = true; }
  fillLegend();
  renderLedger();
  render();
})();
