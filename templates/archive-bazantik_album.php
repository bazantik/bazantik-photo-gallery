<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

\BazantikPhotoGallery\Plugin::theme_header();
?>
<main id="primary" class="site-main bazantik-album-container">
	<?php
	$bazantik_term  = is_tax( 'bazantik_folder' ) ? get_queried_object() : null;
	$bazantik_state = $bazantik_term instanceof \WP_Term ? \BazantikPhotoGallery\Folder_Access::access( $bazantik_term->term_id ) : 'ok';
	?>
	<header class="bazantik-album-header">
		<h1 class="bazantik-album-title">
			<?php
			if ( $bazantik_term instanceof \WP_Term ) {
				single_term_title();
			} else {
				esc_html_e( 'Gallery', 'bazantik-photo-gallery' );
			}
			?>
		</h1>
		<?php if ( $bazantik_term instanceof \WP_Term ) : ?>
			<div class="bazantik-album-breadcrumbs">
				<?php echo \BazantikPhotoGallery\Folder_Access::folder_breadcrumb_html( $bazantik_term ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapováno uvnitř ?>
			</div>
		<?php endif; ?>
		<?php if ( $bazantik_term instanceof \WP_Term && 'ok' === $bazantik_state ) : ?>
			<div class="bazantik-album-description">
				<?php echo wp_kses_post( term_description() ); ?>
			</div>
		<?php endif; ?>
	</header>

	<?php
	if ( $bazantik_term instanceof \WP_Term && 'password' === $bazantik_state ) {
		echo \BazantikPhotoGallery\Folder_Access::password_form_html( $bazantik_term ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapováno uvnitř
	} else {
		echo \BazantikPhotoGallery\Overview::render( $bazantik_term instanceof \WP_Term ? $bazantik_term->term_id : 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapováno uvnitř
	}
	?>
</main>
<?php
\BazantikPhotoGallery\Plugin::theme_footer();
