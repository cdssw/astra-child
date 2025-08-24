<?php
get_header();

$address = get_post_meta(get_the_ID(), 'address', true);
$phone   = get_post_meta(get_the_ID(), 'phone', true);
$lat     = get_post_meta(get_the_ID(), 'lat', true);
$lng     = get_post_meta(get_the_ID(), 'lng', true);
$price   = get_post_meta(get_the_ID(), 'price_min', true);
$season  = get_post_meta(get_the_ID(), 'open_season', true);
?>

<main id="primary" class="site-main ast-container">
  <?php while (have_posts()) : the_post(); ?>
    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>

      <header class="entry-header">
        <h1 class="entry-title"><?php the_title(); ?></h1>
        <div class="entry-meta">
          <?php
          $r_terms = get_the_terms(get_the_ID(),'region');
          $t_terms = get_the_terms(get_the_ID(),'camp_theme');
          $r_names = $r_terms && !is_wp_error($r_terms) ? wp_list_pluck($r_terms,'name') : array();
          $t_names = $t_terms && !is_wp_error($t_terms) ? wp_list_pluck($t_terms,'name') : array();
          if ($r_names) echo esc_html(implode(' / ', $r_names));
          if ($t_names) echo ' · '.esc_html(implode(', ', $t_names));
          ?>
        </div>
      </header>

      <?php if (has_post_thumbnail()) : ?>
        <div class="post-thumb" style="margin:12px 0 18px;"><?php the_post_thumbnail('large'); ?></div>
      <?php endif; ?>

      <section class="mt-2 mb-2" style="border:1px solid #e2e8f0; border-radius:12px; padding:14px;">
        <ul style="list-style:none; padding:0; margin:0; display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:10px;">
          <?php if ($address): ?><li><strong>주소</strong><br><?php echo esc_html($address); ?></li><?php endif; ?>
          <?php if ($phone): ?><li><strong>전화</strong><br><?php echo esc_html($phone); ?></li><?php endif; ?>
          <?php if ($price): ?><li><strong>가격(최소)</strong><br><?php echo esc_html($price); ?></li><?php endif; ?>
          <?php if ($season): ?><li><strong>운영 시즌</strong><br><?php echo esc_html($season); ?></li><?php endif; ?>
        </ul>
      </section>

      <?php if ($lat && $lng): ?>
        <div class="mt-2 mb-2">
          <div class="ad-slot">지도 영역(추후 API 연동)</div>
        </div>
      <?php endif; ?>

      <div class="entry-content">
        <?php the_content(); ?>
      </div>

      <footer class="entry-footer">
        <h3 class="mt-3">인근 캠핑장</h3>
        <?php
        $args = array(
          'post_type'      => 'campground',
          'posts_per_page' => 4,
          'post__not_in'   => array(get_the_ID()),
          'tax_query'      => array(
            array(
              'taxonomy' => 'region',
              'field'    => 'term_id',
              'terms'    => $r_terms ? wp_list_pluck($r_terms,'term_id') : array()
            )
          )
        );
        $rel = new WP_Query($args);
        if ($rel->have_posts()):
          echo '<div class="ast-row">';
          while ($rel->have_posts()): $rel->the_post();
            echo '<article class="'.esc_attr(join(' ', get_post_class('', get_the_ID()))).'">';
            echo '<a href="'.esc_url(get_permalink()).'" class="post-thumb">';
            if (has_post_thumbnail()) the_post_thumbnail('medium_large');
            echo '</a>';
            echo '<header class="entry-header"><h4 class="entry-title" style="margin:8px 0;"><a href="'.esc_url(get_permalink()).'">'.esc_html(get_the_title()).'</a></h4></header>';
            echo '</article>';
          endwhile;
          echo '</div>';
          wp_reset_postdata();
        else:
          echo '<p>관련 캠핑장이 없습니다.</p>';
        endif;
        ?>
      </footer>
    </article>
  <?php endwhile; ?>
</main>

<?php get_footer();