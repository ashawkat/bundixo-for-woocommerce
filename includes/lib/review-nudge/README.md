# Betatech Review Nudge

Reusable, single-file library that asks satisfied users of a WordPress.org
plugin to leave a review. Vendored into each Betatech plugin at
`includes/lib/review-nudge/` — no composer, no external service, no telemetry.

## What it shows

| Surface | Where | When |
| --- | --- | --- |
| Dismissable admin notice | Every wp-admin page | Once eligible (see triggers) |
| Quiet ★ footer line | Screens listed in `footer_screens` | Always, until the user rates or opts out (hidden while the notice is up) |
| "Rate ★" action link | Plugins screen, after the host plugin's own links | Always, until the user rates or opts out |

All three link to `https://wordpress.org/support/plugin/{slug}/reviews/#new-post`.

## Triggers

The notice becomes eligible when **either** of these happens:

- `delay_days` (default 7) have passed since install time; **or**
- the host plugin fires its `success_hook` (e.g. the first order that used a
  bundle coupon) — real usage means the ask happens immediately.

"Maybe later" reschedules by `later_days` (default 14). After `max_reminders`
(default 2) snoozes, and on "No thanks", the plugin stops asking for good.

## Usage in a host plugin

1. Copy this directory into the plugin: `includes/lib/review-nudge/`.
2. In the plugin's boot code (runs on `plugins_loaded`), require and
   instantiate with the plugin's config:

```php
require_once MY_PLUGIN_DIR . 'includes/lib/review-nudge/review-nudge.php';

new \Betatech\Review_Nudge( [
	'slug'           => 'my-plugin-slug',                 // wp.org slug (required)
	'name'           => 'My Plugin',                      // display name (required)
	'textdomain'     => 'my-plugin-slug',                 // for translations; defaults to slug
	'icon'           => MY_PLUGIN_URL . 'assets/icon.svg', // optional notice icon
	'basename'       => plugin_basename( MY_PLUGIN_FILE ), // enables the "Rate ★" action link
	'install_time'   => get_option( 'my_plugin_installed_at' ),
	'delay_days'     => 7,
	'later_days'     => 14,
	'max_reminders'  => 2,
	'success_hook'   => 'my_plugin_success_event',        // fired by the plugin when real usage happens
	'footer_screens' => [ 'my-plugin' ],                  // admin screen id needles (substring match)
] );
```

3. Fire the success hook from the plugin wherever "this user actually got
   value" happens (first conversion, first published project, …):

```php
do_action( 'my_plugin_success_event' );
```

4. Delete the state on uninstall:

```php
delete_option( 'my-plugin-slug_review_nudge' );
```

(Option key is `{slug}_review_nudge`, exactly as configured above.)

## Guarantees and constraints

- **wp.org-safe UX.** No sentiment branching ("rate 5 stars? yes → review,
  no → support" is against the Plugin Directory Guidelines). Everyone gets
  the same ask and the same link.
- **Multi-plugin safe.** State, nonce, and AJAX action names are prefixed
  with the plugin slug. The class guards against a double declaration when
  two plugins vendor the library; the first loaded copy serves both, and
  each plugin passes its own config.
- **Translation-ready.** All strings are translated under the *host*
  plugin's text domain via the `textdomain` config, so they end up in the
  host plugin's .pot.
- **Testing on a dev site:** temporarily hook the eligibility filter:

```php
add_filter( 'betatech_review_nudge_eligible', '__return_true' );
```

## Updating the vendored copy

Canonical source: https://github.com/ashawkat/betatech-review-nudge. When the
library changes, replace the whole `includes/lib/review-nudge/` directory and
re-test the config wiring — the constructor is the only integration surface.
