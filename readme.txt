=== Action Center ===
Contributors: steventrotter
Tags: nonprofit, advocacy, call to action, petition, activism
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish Calls to Action: a filterable Action Center page, detail pages, an embeddable block, a public JSON feed, and AI-assisted creation.

== Description ==

Action Center helps nonprofits and advocacy groups publish Calls to Action (CTAs): petitions, public comment windows, letter-writing campaigns, volunteer asks - anything you want visitors to act on.

Each CTA has a "Why this Matters" summary, an optional deadline (or an Ongoing flag), ordered Steps to Take, copy-paste Sample Texts, Related Links, Files, and YouTube videos, plus Organization and CTA Type tags.

Features:

* Action Center listing page via the [cta_list] shortcode: urgent deadline actions first, then ongoing actions, with type and organization filters.
* Per-CTA detail pages with automatic expired notices once a deadline passes.
* Upcoming CTAs block for featuring current actions on any page.
* Automatic Find Your Legislators section for CTAs tagged with the "Contact Your Legislator" type.
* Public JSON feed at /wp-json/action-center/v1/actions so other websites and apps can display your current actions.
* AI-assisted CTA creation through the WordPress MCP plugin: an assistant like Claude can draft complete CTAs from a link, always as drafts for your review.
* JSON import and export of CTAs.
* Automatic updates: the plugin checks its GitHub repository for new releases and updates through the normal WordPress update flow.

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

== Changelog ==

= 1.1.0 =
* Automatic updates via GitHub releases (bundled Plugin Update Checker library, MIT).

= 1.0.0 =
* First public release.
