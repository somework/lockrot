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
    kvRows: kvRows,
    parseQuery: parseQuery
  };
})();

// The browser never sees this branch; node reads the file for its tests and takes this way out.
if (typeof module !== "undefined" && module.exports) module.exports = LockrotLib;
