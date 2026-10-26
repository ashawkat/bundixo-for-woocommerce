<?php
/**
 * Betatech Review Nudge.
 *
 * Reusable, single-file library that asks satisfied users of a WordPress.org
 * plugin to leave a review. Copy this directory into a plugin
 * (includes/lib/review-nudge/), require review-nudge.php, and instantiate
 * the class with the plugin's configuration — see README.md.
 *
 * Canonical source: https://github.com/ashawkat/betatech-review-nudge
 *
 * Deliberate design rules:
 *
 * - One ask flow, no sentiment branching. The WordPress.org Plugin
 *   Guidelines forbid filtering users by their sentiment ("rate us 5 stars?
 *   yes -> review, no -> support"), so every user gets the same ask and the
 *   same link to the plugin's reviews page.
 * - Usage-based triggers. The prompt becomes eligible after `delay_days`
 *   of install time, or immediately when the host plugin fires its
 *   `success_hook` (e.g. the first order that used a bundle coupon).
 * - Gentle persistence. "Maybe later" reschedules; after `max_reminders`
 *   snoozes the plugin stops asking entirely. "No thanks" stops asking
 *   immediately.
 * - Slug-namespaced state. All options, nonce, and AJAX action names are
 *   prefixed with the plugin slug, so several plugins (each vendoring this
 *   library) can run side by side on one site without colliding.
 *
 * @package Betatech
 */

namespace Betatech;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guard against a double include when more than one plugin on a site
// vendors this library. The first loaded copy serves them all; the class is
// instance-based, so every plugin still passes its own configuration.
if ( class_exists( 'Betatech\Review_Nudge' ) ) {
	return;
}

class Review_Nudge {

	/**
	 * Merged configuration.
	 *
	 * @var array
	 */
	private $config = [];

	/**
	 * Option key holding this plugin's nudge state.
	 *
	 * @var string
	 */
	private $state_key = '';

	/**
	 * Boots the nudge and registers every hook it needs.
	 *
	 * Instantiating is safe on frontend requests too: the admin hooks only
	 * fire in wp-admin, while the `success_hook` listener must fire on the
	 * storefront (e.g. during checkout).
	 *
	 * @param array $config {
	 *     Required: 'slug' (WordPress.org slug) and 'name' (display name).
	 *     Everything else is optional — see the $defaults array.
	 * }
	 */
	public function __construct( array $config ) {
		$defaults = [
			'slug'           => '',
			'name'           => '',
			'textdomain'     => '',
			'icon'           => '',
			'basename'       => '',
			'capability'     => 'manage_options',
			'install_time'   => 0,
			'delay_days'     => 7,
			'later_days'     => 14,
			'max_reminders'  => 2,
			'success_hook'   => '',
			'footer_screens' => [],
		];

		$this->config    = array_merge( $defaults, $config );
		$this->state_key = sanitize_key( $this->config['slug'] . '_review_nudge' );

		if ( '' === $this->config['slug'] || '' === $this->config['name'] ) {
			return;
		}

		if ( '' === $this->config['textdomain'] ) {
			$this->config['textdomain'] = $this->config['slug'];
		}

		add_action( 'admin_init', [ $this, 'register_admin_hooks' ] );
		add_action( 'wp_ajax_' . $this->ajax_action(), [ $this, 'handle_ajax' ] );

		if ( '' !== $this->config['basename'] ) {
			add_filter( 'plugin_action_links_' . $this->config['basename'], [ $this, 'action_links' ], 20 );
		}

		if ( '' !== $this->config['success_hook'] ) {
			add_action( $this->config['success_hook'], [ $this, 'mark_success' ] );
		}
	}

	/**
	 * Registers the display hooks. Runs on admin_init so the notice also
	 * lands on pages rendered before plugins_loaded subscribers settle.
	 *
	 * @return void
	 */
	public function register_admin_hooks() {
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
		add_action( 'admin_footer', [ $this, 'render_footer_prompt' ] );
	}

	// ------------------------------------------------------------------
	// Display
	// ------------------------------------------------------------------

	/**
	 * Renders the dismissable review notice once the plugin is eligible.
	 *
	 * @return void
	 */
	public function render_notice() {
		if ( is_network_admin() || ! current_user_can( $this->config['capability'] ) ) {
			return;
		}

		if ( ! $this->is_eligible() ) {
			return;
		}

		$state = $this->state();

		if ( empty( $state['shown'] ) ) {
			$state['shown'] = 1;
			update_option( $this->state_key, $state, false );
		}

		$this->print_styles();
		?>
		<div class="notice betatech-rn-notice"
			data-rn-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-rn-action="<?php echo esc_attr( $this->ajax_action() ); ?>"
			data-rn-nonce="<?php echo esc_attr( wp_create_nonce( 'betatech-review-nudge-' . $this->config['slug'] ) ); ?>">
			<div class="betatech-rn-inner">
				<?php if ( '' !== $this->config['icon'] ) : ?>
					<img class="betatech-rn-icon" src="<?php echo esc_url( $this->config['icon'] ); ?>" alt="" />
				<?php endif; ?>
				<div class="betatech-rn-text">
					<p class="betatech-rn-heading">
						<strong>
							<?php
							/* translators: %s: plugin name */
							echo esc_html( sprintf( $this->t( 'Enjoying %s?' ), $this->config['name'] ) );
							?>
						</strong>
					</p>
					<p><?php echo esc_html( $this->t( 'If it has been useful for your store, a quick review on WordPress.org helps other shop owners discover the plugin. It only takes a minute.' ) ); ?></p>
					<p class="betatech-rn-buttons">
						<a class="button button-primary" href="<?php echo esc_url( $this->review_url() ); ?>" target="_blank" rel="noopener noreferrer" data-rn-choice="rate">
							<span class="betatech-rn-stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
							<?php echo esc_html( $this->t( 'Rate on WordPress.org' ) ); ?>
						</a>
						<button type="button" class="button" data-rn-choice="later"><?php echo esc_html( $this->t( 'Maybe later' ) ); ?></button>
						<button type="button" class="button-link betatech-rn-decline" data-rn-choice="dismiss"><?php echo esc_html( $this->t( 'No thanks' ) ); ?></button>
					</p>
				</div>
			</div>
			<button type="button" class="notice-dismiss">
				<span class="screen-reader-text"><?php echo esc_html( $this->t( 'Dismiss this notice.' ) ); ?></span>
			</button>
		</div>
		<?php

		$this->print_script();
	}

	/**
	 * Renders the quiet footer prompt on the screens listed in
	 * `footer_screens` (substring match against the screen id). Hidden while
	 * the full notice is up, and gone for good after rate/dismiss.
	 *
	 * @return void
	 */
	public function render_footer_prompt() {
		if ( is_network_admin() || ! current_user_can( $this->config['capability'] ) ) {
			return;
		}

		if ( empty( $this->config['footer_screens'] ) || $this->is_eligible() ) {
			return;
		}

		$state = $this->state();

		if ( ! empty( $state['done'] ) || ! empty( $state['dismissed'] ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$matched = false;

		foreach ( (array) $this->config['footer_screens'] as $needle ) {
			if ( $screen->id === $needle || false !== strpos( (string) $screen->id, (string) $needle ) ) {
				$matched = true;
				break;
			}
		}

		if ( ! $matched ) {
			return;
		}

		$this->print_styles();

		printf(
			'<p class="betatech-rn-footer"><span class="betatech-rn-stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span> <a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( $this->review_url() ),
			esc_html(
				sprintf(
					/* translators: %s: plugin name */
					$this->t( 'Enjoying %s? A one-minute review helps other shop owners find it.' ),
					$this->config['name']
				)
			)
		);
	}

	/**
	 * Appends a "Rate" link to the plugin's action links. Hidden after the
	 * user rated or opted out.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function action_links( $links ) {
		$state = $this->state();

		if ( ! empty( $state['done'] ) || ! empty( $state['dismissed'] ) ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $this->review_url() ),
			esc_html( $this->t( 'Rate ★' ) )
		);

		return $links;
	}

	// ------------------------------------------------------------------
	// State transitions
	// ------------------------------------------------------------------

	/**
	 * Records the host plugin's success event: the prompt becomes eligible
	 * immediately (real usage happened), unless the user already acted.
	 *
	 * @return void
	 */
	public function mark_success() {
		$state = $this->state();

		if ( ! empty( $state['success'] ) || ! empty( $state['done'] ) || ! empty( $state['dismissed'] ) ) {
			return;
		}

		$state['success'] = 1;
		$state['ask_at']  = time();

		update_option( $this->state_key, $state, false );
	}

	/**
	 * Handles the rate/later/dismiss AJAX call.
	 *
	 * @return void
	 */
	public function handle_ajax() {
		check_ajax_referer( 'betatech-review-nudge-' . $this->config['slug'], 'nonce' );

		if ( ! current_user_can( $this->config['capability'] ) ) {
			wp_send_json_error( [ 'message' => $this->t( 'Not allowed.' ) ], 403 );
		}

		$choice = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.

		if ( ! in_array( $choice, [ 'rate', 'later', 'dismiss' ], true ) ) {
			wp_send_json_error( [ 'message' => $this->t( 'Unknown action.' ) ], 400 );
		}

		$state = $this->state();

		if ( 'rate' === $choice ) {
			$state['done'] = 1;
		} elseif ( 'dismiss' === $choice ) {
			$state['dismissed'] = 1;
		} else {
			// "Maybe later" (and the notice's X button): reschedule, or stop
			// asking once the reminder budget is spent.
			$state['reminders'] = (int) $state['reminders'] + 1;

			if ( (int) $state['reminders'] >= (int) $this->config['max_reminders'] ) {
				$state['dismissed'] = 1;
			} else {
				$state['ask_at'] = time() + (int) $this->config['later_days'] * DAY_IN_SECONDS;
			}
		}

		update_option( $this->state_key, $state, false );

		wp_send_json_success();
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	/**
	 * Whether the prompt should be shown on this page load.
	 *
	 * @return bool
	 */
	private function is_eligible() {
		$state = $this->state();

		if ( ! empty( $state['done'] ) || ! empty( $state['dismissed'] ) ) {
			return false;
		}

		$eligible = ! empty( $state['success'] ) || time() >= (int) $state['ask_at'];

		/**
		 * Filters whether the review prompt is eligible. Handy for testing
		 * on a dev site: return true to force the notice.
		 *
		 * @param bool  $eligible
		 * @param array $config This instance's configuration.
		 */
		return (bool) apply_filters( 'betatech_review_nudge_eligible', $eligible, $this->config );
	}

	/**
	 * Loads the nudge state, initializing and persisting it on first use.
	 *
	 * @return array
	 */
	private function state() {
		$state = get_option( $this->state_key, [] );

		if ( ! is_array( $state ) || empty( $state ) ) {
			$installed_at    = (int) $this->config['install_time'];
			$state           = [
				'installed_at' => $installed_at > 0 ? $installed_at : time(),
				'ask_at'       => 0,
				'success'      => 0,
				'reminders'    => 0,
				'shown'        => 0,
				'done'         => 0,
				'dismissed'    => 0,
			];
			$state['ask_at'] = $state['installed_at'] + (int) $this->config['delay_days'] * DAY_IN_SECONDS;

			update_option( $this->state_key, $state, false );
		}

		return $state;
	}

	/**
	 * Reviews page for the plugin on WordPress.org.
	 *
	 * @return string
	 */
	private function review_url() {
		return 'https://wordpress.org/support/plugin/' . $this->config['slug'] . '/reviews/#new-post';
	}

	/**
	 * This instance's AJAX action name.
	 *
	 * @return string
	 */
	private function ajax_action() {
		return $this->config['slug'] . '_review_nudge';
	}

	/**
	 * Translates a string under the host plugin's text domain, so the
	 * library's strings ride the host plugin's own .pot/.po files.
	 *
	 * @param string $text Source string.
	 * @return string
	 */
	private function t( $text ) {
		return __( $text, $this->config['textdomain'] );
	}

	/**
	 * Prints the notice/footer CSS once per request.
	 *
	 * @return void
	 */
	private function print_styles() {
		static $printed = false;

		if ( $printed ) {
			return;
		}

		$printed = true;
		?>
		<style id="betatech-review-nudge">
			.betatech-rn-notice { padding: 18px 24px; }
			.betatech-rn-inner { display: flex; align-items: center; gap: 16px; }
			.betatech-rn-icon { width: 46px; height: 46px; border-radius: 9px; flex: 0 0 auto; }
			.betatech-rn-text p { margin: 0 0 6px; }
			.betatech-rn-text .betatech-rn-heading { margin-bottom: 2px; font-size: 14px; }
			.betatech-rn-buttons { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin: 12px 0 0 !important; }
			.betatech-rn-decline { color: #787c82; text-decoration: none; }
			.betatech-rn-decline:hover { color: #d63638; }
			.betatech-rn-stars { color: #dba617; letter-spacing: 3px; margin-right: 6px; }
			.betatech-rn-notice.betatech-rn-leaving { opacity: 0; transition: opacity .3s ease; }
			.betatech-rn-footer { margin: 0; padding: 14px 20px 0; text-align: center; font-size: 12px; }
			.betatech-rn-footer, .betatech-rn-footer a { color: #787c82; text-decoration: none; }
			.betatech-rn-footer a:hover { color: #2271b1; }
		</style>
		<?php
	}

	/**
	 * Prints the notice's click handling script once per request.
	 *
	 * @return void
	 */
	private function print_script() {
		static $printed = false;

		if ( $printed ) {
			return;
		}

		$printed = true;
		?>
		<script>
		( function () {
			'use strict';

			document.addEventListener( 'click', function ( event ) {
				if ( ! event.target || ! event.target.closest ) {
					return;
				}

				var notice = event.target.closest( '.betatech-rn-notice' );

				if ( ! notice ) {
					return;
				}

				var control = event.target.closest( '[data-rn-choice]' );
				var dismissX = event.target.closest( '.notice-dismiss' );

				if ( ! control && ! dismissX ) {
					return;
				}

				var choice = control ? control.getAttribute( 'data-rn-choice' ) : 'later';

				send( notice, choice );

				// "Rate" opens the reviews page in a new tab; the anchor's
				// default action proceeds. Everything else closes the notice.
				if ( 'rate' !== choice ) {
					event.preventDefault();
				}

				notice.classList.add( 'betatech-rn-leaving' );
				setTimeout( function () {
					if ( notice.parentNode ) {
						notice.parentNode.removeChild( notice );
					}
				}, 350 );
			} );

			function send( notice, choice ) {
				var body = new FormData();

				body.append( 'action', notice.getAttribute( 'data-rn-action' ) );
				body.append( 'nonce', notice.getAttribute( 'data-rn-nonce' ) );
				body.append( 'choice', choice );

				window.fetch( notice.getAttribute( 'data-rn-ajax' ), {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				} ).catch( function () {} );
			}
		}() );
		</script>
		<?php
	}
}
