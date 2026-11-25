<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

\BazantikPhotoGallery\Plugin::theme_header();

$bazantik_album_id = get_the_ID();

?>
<main id="primary" class="site-main bazantik-album-container">
	<?php
	while ( have_posts() ) :
		the_post();

		// Správce alba heslo zadávat nemusí (jako ve výpisech a ve shortcodu)
		$bazantik_locked = post_password_required() && ! current_user_can( 'edit_post', $bazantik_album_id );
		if ( $bazantik_locked ) :
			?>
			<header class="bazantik-album-header">
				<h1 class="bazantik-album-title"><?php echo esc_html( get_post_field( 'post_title', $bazantik_album_id ) ); ?></h1>
			</header>
			<div class="bazantik-password-form-wrapper">
				<?php echo get_the_password_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- formulář jádra WordPressu ?>
			</div>
			<?php
		else :
			?>
			<header class="bazantik-album-header">
				<h1 class="bazantik-album-title"><?php echo esc_html( get_post_field( 'post_title', $bazantik_album_id ) ); ?></h1>
				<?php
				$bazantik_crumbs = \BazantikPhotoGallery\Folder_Access::breadcrumb_html( (int) $bazantik_album_id );
				if ( '' !== $bazantik_crumbs ) :
					?>
					<div class="bazantik-album-breadcrumbs">
						<?php echo $bazantik_crumbs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapováno uvnitř ?>
					</div>
				<?php endif; ?>
				<div class="bazantik-album-description">
					<?php
					if ( post_password_required() ) {
						// the_content() by u zaheslovaného alba místo popisu vypsalo formulář s heslem
						echo apply_filters( 'the_content', get_post_field( 'post_content', $bazantik_album_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtr the_content
					} else {
						the_content();
					}
					?>
				</div>
			</header>

			<section class="bazantik-album-gallery-wrap">
				<?php
				$bazantik_gallery = new Shortcodes_Blocks();
				$bazantik_gallery->render_gallery_markup( $bazantik_album_id );
				?>
			</section>
			<?php
		endif;
	endwhile;
	?>
</main>
<?php
\BazantikPhotoGallery\Plugin::theme_footer();
