<?php

/**
 * Search results (child theme)
 */
get_header();
$q = get_search_query();
$total = $wp_query->found_posts;
?>
<main id="primary" class="site-main ast-container search-wrap">
  <header class="search-header">
    <h1 class="search-title">검색: “<?php echo esc_html($q); ?>”</h1>
    <p class="search-sub"><?php echo number_format_i18n($total); ?>건의 결과</p>
  </header>

  <?php if (have_posts()) : ?>
    <div class="search-list">
      <?php
      $re = $q ? '/' . preg_quote($q, '/') . '/iu' : null;
      while (have_posts()) : the_post();
        $type  = get_post_type();
        $label = ($type === 'campground') ? '캠핑장' : (($type === 'post') ? '블로그' : '페이지');
        $addr  = ($type === 'campground') ? get_post_meta(get_the_ID(), 'address', true) : '';

        $title = get_the_title();
        $ex    = get_the_excerpt();
        if ($re) {
          $title = preg_replace($re, '<mark class="kw">$0</mark>', $title);
          $ex    = preg_replace($re, '<mark class="kw">$0</mark>', $ex);
        }
      ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class('search-card type-' . $type); ?>>
          <div class="meta-top">
            <span class="badge type"><?php echo esc_html($label); ?></span>
            <time class="date" datetime="<?php echo esc_attr(get_the_date('c')); ?>">
              <?php echo esc_html(get_the_date('Y-m-d')); ?>
            </time>
          </div>
          <h2 class="title"><a href="<?php the_permalink(); ?>"><?php echo $title; ?></a></h2>
          <?php if ($addr): ?><div class="addr"><?php echo esc_html($addr); ?></div><?php endif; ?>
          <div class="excerpt"><?php echo wp_kses_post($ex); ?></div>
        </article>
      <?php endwhile; ?>
    </div>
    <?php the_posts_pagination(array('mid_size' => 1)); ?>
  <?php else: ?>
    <p class="no-result">“<?php echo esc_html($q); ?>”에 대한 결과가 없습니다.
      <a href="<?php echo esc_url(get_post_type_archive_link('campground')); ?>">캠핑장 목록으로</a>
    </p>
  <?php endif; ?>
</main>
<?php get_footer();
