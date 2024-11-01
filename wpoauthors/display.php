<div class="wrap">
	<h2><?php _e('Process WP-o-Matic posts', 'wpoauthors'); ?></h2>
	<?php if(!get_option('wpo_version')): ?>
	<p><?php _e ('WP-o-Matic is not installed', 'wpoauthors'); ?></p>
	<?php else: ?>
	<p><?php _e('You can make so that posts imported by WP-o-Matic campaigns register their original author
		in the database. This will make listing of those posts a whole lot easier.', 'wpoauthors'); ?></p>
	<form action="" method="post" accept-charset="utf-8">
		<p><input type="checkbox" name="process_wpomatic_posts[]" value="all" /><?php _e('Process all
			existing posts. Warning, this may take time. This will overwrite any prior processing.', 'wpoauthors'); ?>
		</p>
		<p><input type="checkbox" name="process_wpomatic_posts[]" value="new" <?= get_option('wpoa_processwpoposts') ? 'checked' : ''?>/>
			<?php _e('Process posts when they are imported by WP-o-matic.', 'wpoauthors'); ?>
		</p>
        <p>
            Author name: you can change the way the author names are fabricated, for the imported posts. <br />
            <input type="text" name="author_template" value="<?= get_option('wpoa_authornametemplate')?>" size="55" />
        </p>
		<input type="hidden" name="wpoa_wpomatic" value="true" />
		<input type="submit" />
	</form>
	<?php endif; ?>
</div>