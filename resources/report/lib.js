/**
 * The part of the report page that has no DOM in it.
 *
 * Escaping, the two URL and command checks a hostile document runs into, the date arithmetic and
 * the search grammar. They are here rather than in report.js because they are the part worth
 * testing on its own: the page cannot be loaded outside a browser, but this file can, and
 * `tests/js/lib.test.js` does exactly that with node's own test runner and nothing installed.
 *
 * Two consumers, one file: the browser gets it spliced in ahead of report.js by HtmlFormatter and
 * reads the global; node reads the export at the bottom. Nothing here touches `document`,
 * `window` or the clock — where the current time matters it is a parameter.
 */
var LockrotLib = (function () {
  "use strict";

  /**
   * Text on its way into markup. `&`, `<`, `>` and `"` become entities; `'` does not, because
   * every attribute this page writes is delimited with `"`.
   */
  function esc(s) {
    return String(s === null || s === undefined ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }

  /**
   * A URL the page may put in an href, or null.
   *
   * The page renders data it did not produce. A package's own metadata reaches it — the name, the
   * description, the repository, and through the advisory feed a `link` and a title — and so does
   * the document's `$schema`. Any of those arriving as `javascript:` would be a link that runs
   * script in whatever origin the page is open in. `target="_blank"` happens to stop Chrome
   * following such a link today, which is luck, not a defence: it is the scheme that has to be
   * checked, once, where the href is written.
   */
  function safeHref(url) {
    var value = typeof url === "string" ? url.trim() : "";
    return /^https?:\/\/[^\s<>"']+$/i.test(value) ? value : null;
  }

  /**
   * The `composer require` line for a finding, or null when either half is not the shape it has to
   * be.
   *
   * This one is offered with a button that puts it on the clipboard, and what the button copies is
   * the raw value — the HTML escaping is undone by the parser on the way back out of the
   * attribute. Somewhere between that clipboard and a shell prompt there is no escaping left at
   * all, so a `suggested_constraint` carrying a newline and a second command would be pasted and
   * run. lockrot's own constraints are always well formed; a document from somewhere else is not
   * lockrot's own. A package name is a vendor and a name, a constraint is the small grammar
   * Composer accepts, and anything else means no command is offered.
   *
   * The constraint is quoted because the grammar Composer accepts is full of characters a shell
   * reads first: `composer require laravel/framework >=10.0 <12.0` writes a file called `=10.0`,
   * reads its input from `12.0` and passes Composer no constraint at all, and `~2.0|^3.0` pipes
   * into a command named `^3.0`. Single quotes are the right ones: the grammar above has no `'`
   * in it, so nothing can close the quote it is wrapped in.
   */
  function installCommand(name, constraint) {
    if (!/^[A-Za-z0-9]([A-Za-z0-9._-]*)\/[A-Za-z0-9]([A-Za-z0-9._-]*)$/.test(String(name))) return null;
    var value = String(constraint);
    if (value.length > 100 || !/^[A-Za-z0-9.,^~><=!|*\/ @_-]+$/.test(value)) return null;

    return "composer require " + name + " '" + value + "'";
  }

  /** The date out of an ISO timestamp, or an em dash when there is none. */
  function day(iso) { return iso ? String(iso).slice(0, 10) : "—"; }

  /** Years between `iso` and `now`, fractional; null when undated. */
  function years(iso, now) {
    if (!iso) return null;
    return (now - new Date(iso)) / (365.25 * 24 * 3600 * 1000);
  }

  /** How long ago, in the units a reader can hold: months under a year, tenths of a year over it. */
  function ageText(iso, now) {
    var y = years(iso, now);
    if (y === null) return "undated";
    if (y < 1) return Math.max(1, Math.round(y * 12)) + " mo ago";

    return y.toFixed(1) + " y ago";
  }

  function plural(n, one, many) {
    return n + " " + (n === 1 ? one : many);
  }

  /**
   * What the packages table sorts on for the libyears column: the value, with an unmeasured
   * package (null, or a document from before the field) below every measured one, zero included.
   */
  function libyearsSortKey(finding) {
    var value = finding ? finding.libyears : null;
    return value === null || value === undefined ? -1 : Number(value);
  }

  /**
   * A number from the document formatted to `digits` decimals, or null when the value is not a
   * finite number — `null`, a string, `Infinity`. Checked before `Number()`, not after: `Number(null)`
   * is a finite zero, and a page that printed `0.0` for a missing value would be asserting something
   * the document does not say.
   */
  function fixed(value, digits) {
    return typeof value === "number" && isFinite(value) ? value.toFixed(digits) : null;
  }

  /**
   * Why a finding carries no libyears value, in the words the report's `unmeasured` block counts
   * it under, read off the finding the way the block itself does: the note names a package no
   * repository was asked about or one whose metadata did not come, a dev version is a branch
   * snapshot, and what is left had metadata and no pair of dates to compare. An empty string
   * for a measured finding.
   */
  function libyearsReason(finding) {
    if (!finding || fixed(finding.libyears, 1) !== null) return "";
    if (finding.libyears !== null && finding.libyears !== undefined) return "not a number in this document";
    var note = finding.note;
    if (note === "not from a Composer repository, not checked") return "not from a Composer repository";
    if (note) return "metadata unavailable";
    var version = String(finding.version || "").replace(/#.*$/, "");
    if (/^dev-/.test(version) || /-dev$/.test(version)) return "branch snapshot";

    return "no release date lockrot trusts";
  }

  /**
   * The libyears block, minus the total, as the items under the ledger's figure: `across 191 of
   * 200 packages`, `94.5 from direct requirements`, `furthest behind smalot/pdfparser v1.1.0 at
   * 4.7` — the table footer's items after the number, in its words, one string each so the page
   * can wrap between them and never inside one. `none of the 200 packages could be measured`
   * when nothing was, `nothing to measure` on an empty run, and no items for a block that is not
   * one. Plain text: the caller uses textContent or escapes, since the package name comes from
   * the document.
   */
  function libyearsItems(block) {
    if (!block || typeof block.measured !== "number") return [];
    var unmeasured = 0;
    var reasons = block.unmeasured && typeof block.unmeasured === "object" ? block.unmeasured : {};
    Object.keys(reasons).forEach(function (k) { unmeasured += Number(reasons[k]) || 0; });
    var packages = block.measured + unmeasured;
    if (!block.measured) {
      if (!packages) return ["nothing to measure"];
      return [packages === 1 ? "the one package could not be measured" : "none of the " + packages + " packages could be measured"];
    }
    var scope = block.measured === packages
      ? (packages === 1 ? "the one package" : "all " + packages + " packages")
      : block.measured + " of " + packages + " packages";
    var items = ["across " + scope];
    var worst = block.furthest_behind;
    if (worst && typeof worst === "object") {
      var direct = fixed(block.direct_requirements, 1);
      if (direct !== null) items.push(direct + " from direct requirements");
      var behind = fixed(worst.libyears, 1);
      items.push("furthest behind " + worst.package + " " + worst.version + (behind === null ? "" : " at " + behind));
    }

    return items;
  }

  /** {@see libyearsItems} as the one line the Run tab prints, joined like the table footer. */
  function libyearsSummary(block) {
    return libyearsItems(block).join(" \u00b7 ");
  }

  /**
   * Definition rows, minus the ones with nothing to say. A row whose value is null is dropped
   * rather than printed as a dash: the lock entry and the provenance are built from `--explain`
   * data, and a document that carries only the report has none of it — five dashes under a heading
   * reads as a broken page, where three real rows and no heading reads as what is known.
   *
   * Values arrive escaped, because some of them are links.
   */
  function kvRows(pairs) {
    return pairs.filter(function (p) { return p[1] !== null && p[1] !== undefined && p[1] !== ""; })
      .map(function (p) { return "<dt>" + esc(p[0]) + "</dt><dd>" + p[1] + "</dd>"; }).join("");
  }

  /**
   * The search box, read as terms. A bare word is matched against the text of a finding; a
   * `key:value` pair narrows one field, and the same key twice widens rather than narrows, because
   * `verdict:silent verdict:abandoned` means either of them.
   */
  function parseQuery(raw) {
    var terms = { text: [], verdict: [], priority: [], signal: [], severity: [], cve: [], direct: null, dev: null };
    String(raw === null || raw === undefined ? "" : raw).trim().split(/\s+/).forEach(function (part) {
      if (!part) return;
      var m = part.match(/^(verdict|priority|signal|severity|cve|direct|dev):(.+)$/i);
      if (!m) { terms.text.push(part.toLowerCase()); return; }
      var key = m[1].toLowerCase(), val = m[2].toLowerCase();
      if (key === "direct" || key === "dev") { terms[key] = (val === "yes" || val === "true" || val === "1"); return; }
      terms[key].push(key === "signal" || key === "cve" ? val.toUpperCase() : val);
    });

    return terms;
  }

  return {
    esc: esc,
    safeHref: safeHref,
    installCommand: installCommand,
    day: day,
    years: years,
    ageText: ageText,
    plural: plural,
    fixed: fixed,
    libyearsItems: libyearsItems,
    libyearsSummary: libyearsSummary,
    libyearsReason: libyearsReason,
    libyearsSortKey: libyearsSortKey,
    kvRows: kvRows,
    parseQuery: parseQuery
  };
})();

// The browser never sees this branch; node reads the file for its tests and takes this way out.
if (typeof module !== "undefined" && module.exports) module.exports = LockrotLib;
