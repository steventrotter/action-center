=== Action Center ===
Contributors: steventrotter
Tags: nonprofit, advocacy, call to action, petition, activism
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.3.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish Calls to Action: a filterable Action Center page, detail pages, an embeddable block, a public JSON feed, and AI-assisted creation.

== Description ==

Action Center helps nonprofits and advocacy groups publish Calls to Action (CTAs): petitions, public comment windows, letter-writing campaigns, volunteer asks - anything you want visitors to act on.

Each CTA has a "Why this Matters" summary, an optional deadline (or an Ongoing flag), ordered Steps to Take, copy-paste Sample Texts, Related Links, Files, and YouTube videos, plus Organization and CTA Type tags.

Features:

* Two action formats: Simple (the classic display) and Guided, an interactive comment builder that helps supporters assemble and edit a comment from selectable talking points and their own words, then copy it and hand off to the submission form. Soft engagement counters track comments written and confirmed submitted, with an optional goal meter.
* Action Center listing page via the [cta_list] shortcode: urgent deadline actions first, then ongoing actions, with type and organization filters.
* Per-CTA detail pages with automatic expired notices once a deadline passes.
* Upcoming CTAs block for featuring current actions on any page.
* Automatic Find Your Legislators section for CTAs tagged with the "Contact Your Legislator" type.
* Public JSON feed at /wp-json/action-center/v1/actions so other websites and apps can display your current actions, including a full-content mode for native apps.
* AI-assisted CTA creation through the WordPress MCP plugin: an assistant like Claude can draft complete CTAs from a link, always as drafts for your review.
* JSON import and export of CTAs.

Full documentation lives inside the plugin at Settings > Action Center > Documentation.

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload Plugin, then activate it.
2. Create a page for your Action Center and add the [cta_list] shortcode.
3. Select that page under Settings > Action Center.
4. Add your first CTA under the CTAs menu and publish.

== Frequently Asked Questions ==

= Does deleting the plugin remove my CTAs? =

Yes. Uninstalling (deleting) the plugin removes all CTAs, their tags, and the plugin settings. Deactivating does not remove anything. Export your CTAs from Settings > Action Center first if you want a backup.

= Is the feed private? =

No. The feed intentionally exposes your published, active CTAs so partner sites can amplify them. Drafts, expired, and ended CTAs are never included.

== Screenshots ==

1. The Action Center listing page: urgent deadline actions first, then ongoing actions, with type and organization filters.
2. A Guided action: the comment builder with selectable talking points, personal prompts, a live editable draft, and a goal meter.
3. A Simple action detail page with Steps to Take and copy-paste sample text.
4. The Upcoming CTAs block embedded in a page.
5. Editing a CTA in the admin: action format, deadline, steps, and guided builder fields.

== Changelog ==

= 1.3.0 =
* New: Guided action format, an interactive comment builder. Editors choose Simple or Guided per action; Guided actions offer selectable talking points and personal prompt fields that assemble into an editable draft, a copy button, a submission hand-off, and an "I submitted" confirmation.
* New: soft engagement counters (comments written and confirmed submitted) with an optional goal meter, updated live and served through the public feed. A new public route, action-center/v1/track, records these; it stores only two integers and nothing about the visitor.
* Feed: each item now reports its `format`, and `?full=1` includes a `guided` block (talking points, prompts, framing, submission URL, goal, and counts) for guided actions.
* Security: the AI creation tool no longer lets a user without publish rights push a CTA live; a requested publish is downgraded to draft unless the user can actually publish.
* Fixed: import and export now round-trip every field correctly (the sample-text key mismatch and a nonexistent taxonomy reference are resolved), imported CTAs arrive as drafts for review, and uploads are validated and size-capped.
* Fixed: the listing filters now work without JavaScript and no longer change context on select (WCAG 2.2 3.2.2); they submit with a button.
* Fixed: listing pagination now works when the shortcode is on a static Page.
* Fixed: the Ongoing Actions query is now bounded instead of loading every ongoing action at once.
* Fixed: the sample-text copy control is now a real button using the modern clipboard API with a screen-reader announcement.
* Fixed: the import confirmation notice now displays, and export is sent before page output.
* Hardening: URL fields are stored with esc_url_raw; the block ships with a stylesheet and a consistent text domain; editor assets load only on the CTA screen.

= 1.2.0 =
* Feed: new `?full=1` parameter adds a `content` object to each item with the full body (summary_html, steps, sample_texts, links, videos, button_text), so apps and partner sites can show a complete action without an authenticated request.
* Feed: every item now carries a `modified` timestamp in UTC, so a service watching the feed can tell a new action from an edited one.
* Feed: `image` is now the 768px size instead of 300px, which looks far better on a phone. The old size is still available as `image_small`.

= 1.1.1 =
* Fixed: feed titles and summaries now fully decode HTML entities (curly apostrophes etc. arrive as plain text).

= 1.1.0 =
* Automatic updates via GitHub releases (bundled Plugin Update Checker library, MIT).

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.3.0 =
Adds the Guided comment builder and soft engagement counters, plus accessibility, import/export, and security fixes.
