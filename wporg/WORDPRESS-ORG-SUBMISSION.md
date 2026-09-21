# Action Center - WordPress.org Submission

This is the plan for listing Action Center in the WordPress.org plugin directory,
which gives you a public install count, ratings, and native updates.

The submission build differs from the GitHub build in one way: the self-hosted
updater (the Plugin Update Checker library in `lib/`) and its code are removed,
because WordPress.org forbids plugins from updating themselves. Your ECC and test
sites keep the GitHub/updater build until WordPress.org approves this one.

## The build to submit

`builds/action-center-wporg-submission.zip` (version 1.3.0, updater removed,
readme cleaned, Tested up to 7.1). This is the file you upload for review.

## Step 1: Submit for review (you)

1. Sign in at wordpress.org with your @steventrotter account.
2. Go to https://wordpress.org/plugins/developers/add/
3. Upload `action-center-wporg-submission.zip`.
4. You get a confirmation email, then a manual review by the plugins team.
   Approval usually takes a few days to a few weeks. They may reply with
   requested changes (a generic-name rename is the most likely one for us).

## Step 2: Listing assets (needed for the public page, not for review)

Provide these when ready. They live in a `.wordpress-org/` folder that the
deploy action pushes to SVN, or you can add them by SVN directly:

- Icon: `icon-256x256.png` and `icon-128x128.png` (square).
- Banner: `banner-1544x500.png` and `banner-772x250.png`.
- Screenshots: `screenshot-1.png` ... `screenshot-5.png`, matching the five
  captions in readme.txt (listing page, guided builder, simple detail page,
  the block, the admin editor).

## Step 3: After approval - automated deploys (I set up, you add secrets)

WordPress.org emails you an SVN repository URL. Then:

1. Move `wporg/deploy.yml` to `.github/workflows/deploy.yml` at the repo root.
2. Move `wporg/.distignore` to the repo root (keeps `lib/`, `builds/`,
   `.github`, and dev files out of what gets published).
3. In the GitHub repo: Settings > Secrets and variables > Actions, add
   `SVN_USERNAME` and `SVN_PASSWORD` (your wordpress.org login).
4. Put the listing assets in `.wordpress-org/`.
5. From then on, publishing a GitHub release deploys that tag to WordPress.org.

## Cutover note

Once "action-center" exists on WordPress.org, a site still running the GitHub
build could start seeing update prompts from WordPress.org (same slug). When you
are ready to cut over, your sites reinstall the plugin from WordPress.org. If you
want to keep the GitHub build isolated during the interim, add
`Update URI: https://github.com/steventrotter/action-center` to that build's
header - tell me and I will prepare it.
