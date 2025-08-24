<?php
get_header(); ?>

<main id="primary" class="site-main ast-container">
  <header class="page-header">
    <h1 class="page-title">캠핑장 목록</h1>
    <form method="get" action="<?php echo esc_url(get_post_type_archive_link('campground')); ?>" class="mt-2 mb-3" style="display:flex; gap:10px; flex-wrap:wrap;">
      <select name="region">
        <option value="">지역 전체</option>
        <?php
        $regions = get_terms(array('taxonomy'=>'region','hide_empty'=>false));
        $cur_r = isset($_GET['region']) ? sanitize_text_field($_GET['region']) : '';
        foreach ($regions as $t) {
          printf('<option value="%s"%s>%s</option>', esc_attr($t->slug), selected($cur_r, $t->slug, false), esc_html($t->name));
        }
        ?>
      </select>

      <select name="camp_theme">
        <option value="">테마 전체</option>
        <?php
        $themes = get_terms(array('taxonomy'=>'camp_theme','hide_empty'=>false));
        $cur_t = isset($_GET['camp_theme']) ? sanitize_text_field($_GET['camp_theme']) : '';
        foreach ($themes as $t) {
          printf('<option value="%s"%s>%s</option>', esc_attr($t->slug), selected($cur_t, $t->slug, false), esc_html($t->name));
        }
        ?>
      </select>

      <button type="submit" class="ast-button">필터 적용</button>
    </form>
  </header>

  <?php if (have_posts()) : ?>
    <div class="ast-row">
      <?php while (have_posts()) : the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
          <a href="<?php the_permalink(); ?>" class="post-thumb">
            <?php if (has_post_thumbnail()) {
              the_post_thumbnail('medium_large');
            } ?>
          </a>
          <header class="entry-header">
            <h2 class="entry-title" style="margin:0;">
              <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
            </h2>
            <div class="entry-meta">
              <?php
              $r_terms = get_the_terms(get_the_ID(), 'region');
              $t_terms = get_the_terms(get_the_ID(), 'camp_theme');
              $r_names = $r_terms && !is_wp_error($r_terms) ? wp_list_pluck($r_terms,'name') : array();
              $t_names = $t_terms && !is_wp_error($t_terms) ? wp_list_pluck($t_terms,'name') : array();
              echo esc_html(implode(' / ', $r_names));
              if (!empty($t_names)) echo ' · '.esc_html(implode(', ', $t_names));
              ?>
            </div>
          </header>
          <div class="entry-content">
            <?php the_excerpt(); ?>
          </div>
        </article>
      <?php endwhile; ?>
    </div>

    <?php the_posts_pagination(array('mid_size'=>1)); ?>

  <?php else : ?>
    <p>표시할 캠핑장이 없습니다.</p>
  <?php endif; ?>
</main>

<?php get_footer();